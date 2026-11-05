<?php
declare(strict_types=1);

/**
 * Offline failure-path tests for jenx-collect.php.
 * Loads function definitions only. No production config/state, network access,
 * GitHub API calls, or workflow dispatches.
 * Usage: php jenx-collect-failure-test.php [/path/to/jenx-collect.php]
 */
$collectorPath = $argv[1] ?? '/var/www/jenx/jenx-collect.php';
if (!is_file($collectorPath) || !is_readable($collectorPath)) {
    fwrite(STDERR, "FAIL: collector file not readable: {$collectorPath}\n");
    exit(2);
}
$source = file_get_contents($collectorPath);
$marker = '/* ----------------------------- Main ---------------------------------- */';
$pos = is_string($source) ? strpos($source, $marker) : false;
if ($pos === false) {
    fwrite(STDERR, "FAIL: collector main marker not found.\n");
    exit(2);
}
$defs = substr($source, 0, $pos);
$defs = preg_replace('/^<\?php\s*/', '', $defs, 1);
$defs = preg_replace('/^require_once\s+[^;]+;\s*$/m', '', $defs);
if (!is_string($defs) || trim($defs) === '') {
    fwrite(STDERR, "FAIL: could not prepare collector definitions.\n");
    exit(2);
}
$tests = 0;
$failures = 0;
$fixtureDownload = ['ok' => false];
$fixtureZipPath = null;

function ftAssert(bool $ok, string $label): void {
    global $tests, $failures;
    ++$tests;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) ++$failures;
}
function jenxGithubDownloadArtifactZip(array $config, int $artifactId, string $destination): array {
    global $fixtureDownload, $fixtureZipPath;
    if (($fixtureDownload['ok'] ?? false) !== true) return $fixtureDownload;
    if (!is_string($fixtureZipPath) || !is_file($fixtureZipPath)) return ['ok' => false];
    return copy($fixtureZipPath, $destination) ? ['ok' => true] : ['ok' => false];
}
eval($defs);

$requestId = '11111111-2222-4333-8444-555555555555';
$runId = 123456789;
$record = [
    'request_id' => $requestId,
    'release_tag' => 'v9.9.9-test',
    'expected_source_commit' => str_repeat('a', 40),
    'test_only' => true,
    'status' => 'VERIFYING',
];
$config = [
    'GITHUB_WORKFLOW_ID' => '399052069',
    'GITHUB_REPOSITORY' => 'HUMNN-INNOVATIONS/foundd',
    'IMAGE_NAME' => 'ghcr.io/humnn-innovations/foundd',
    'BUILD_ARTIFACT_PREFIX' => 'jenx-build-result-',
];
$valid = [
    'schema_version' => 2,
    'request_id' => $requestId,
    'release_tag' => 'v9.9.9-test',
    'source_commit' => str_repeat('a', 40),
    'image' => $config['IMAGE_NAME'],
    'digest' => 'sha256:' . str_repeat('c', 64),
    'workflow_run_id' => (string)$runId,
    'test_only' => true,
    'status' => 'success',
];

/* GitHub response handling */
$apiCases = [
    [['transport_error'=>'timeout','http_status'=>0,'json'=>null], 'transport error rejected'],
    [['transport_error'=>null,'http_status'=>401,'json'=>['message'=>'unauthorized']], 'HTTP 401 rejected'],
    [['transport_error'=>null,'http_status'=>404,'json'=>['message'=>'missing']], 'HTTP 404 rejected'],
    [['transport_error'=>null,'http_status'=>500,'json'=>['message'=>'failure']], 'HTTP 500 rejected'],
    [['transport_error'=>null,'http_status'=>200,'json'=>null], 'HTTP 200 missing JSON rejected'],
    [['transport_error'=>null,'http_status'=>200,'json'=>'not-array'], 'HTTP 200 scalar JSON rejected'],
];
foreach ($apiCases as [$response, $label]) {
    try { jenxCollectRequireJson($response, 'failure-test'); ftAssert(false, $label); }
    catch (RuntimeException) { ftAssert(true, $label); }
}
try {
    $j = jenxCollectRequireJson(['transport_error'=>null,'http_status'=>200,'json'=>['artifacts'=>[]]], 'test');
    ftAssert(($j['artifacts'] ?? null) === [], 'valid HTTP 200 JSON accepted');
} catch (Throwable $e) { ftAssert(false, 'valid HTTP 200 JSON accepted: '.$e->getMessage()); }

/* Artifact contract field mismatch cases */
try { jenxCollectValidateResult($valid, $record, $config, $runId); ftAssert(true, 'valid schema-v2 artifact accepted'); }
catch (Throwable $e) { ftAssert(false, 'valid schema-v2 artifact accepted: '.$e->getMessage()); }
$mismatches = [
    'schema_version'=>1,
    'request_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    'release_tag'=>'v0.0.0-wrong',
    'source_commit'=>str_repeat('d',40),
    'image'=>'ghcr.io/attacker/other',
    'digest'=>'not-a-digest',
    'workflow_run_id'=>'987654321',
    'test_only'=>false,
    'status'=>'failure',
];
foreach ($mismatches as $field=>$value) {
    $bad = $valid; $bad[$field] = $value;
    try { jenxCollectValidateResult($bad, $record, $config, $runId); ftAssert(false, "mismatch rejected: {$field}"); }
    catch (UnexpectedValueException) { ftAssert(true, "mismatch rejected: {$field}"); }
}
foreach ([
    'uppercase digest'=>'sha256:'.str_repeat('C',64),
    'short digest'=>'sha256:'.str_repeat('c',63),
    'long digest'=>'sha256:'.str_repeat('c',65),
    'wrong algorithm'=>'sha512:'.str_repeat('c',64),
] as $label=>$digest) {
    $bad=$valid; $bad['digest']=$digest;
    try { jenxCollectValidateResult($bad,$record,$config,$runId); ftAssert(false,"{$label} rejected"); }
    catch (UnexpectedValueException) { ftAssert(true,"{$label} rejected"); }
}
foreach ([123456789, 'abc', '-1', '123456788'] as $badId) {
    $bad=$valid; $bad['workflow_run_id']=$badId;
    try { jenxCollectValidateResult($bad,$record,$config,$runId); ftAssert(false,'bad run ID rejected: '.var_export($badId,true)); }
    catch (UnexpectedValueException) { ftAssert(true,'bad run ID rejected: '.var_export($badId,true)); }
}
foreach ([['schema_version','2'], ['test_only',1], ['status','SUCCESS']] as [$field,$value]) {
    $bad=$valid; $bad[$field]=$value;
    try { jenxCollectValidateResult($bad,$record,$config,$runId); ftAssert(false,"bad type/value rejected: {$field}"); }
    catch (UnexpectedValueException) { ftAssert(true,"bad type/value rejected: {$field}"); }
}

/* Locally generated ZIP fixtures */
if (!class_exists(ZipArchive::class)) {
    echo "SKIP: ZIP cases (PHP ZIP extension unavailable).\n";
} else {
    $dir = sys_get_temp_dir().'/jenx-failure-test-'.bin2hex(random_bytes(6));
    if (!mkdir($dir,0700)) { fwrite(STDERR,"Cannot create test temp dir.\n"); exit(2); }
    $makeZip = static function(string $path, array $entries): bool {
        $z=new ZipArchive();
        if ($z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) return false;
        foreach ($entries as $name=>$body) if (!$z->addFromString($name,$body)) { $z->close(); return false; }
        return $z->close();
    };
    $json=json_encode($valid,JSON_THROW_ON_ERROR);
    $zipCases = [
        'valid ZIP/JSON'=>[['build-result.json'=>$json],true],
        'missing result file'=>[['other.json'=>$json],false],
        'unexpected nested path'=>[['folder/build-result.json'=>$json],false],
        'extra ZIP entry'=>[['build-result.json'=>$json,'extra.txt'=>'x'],false],
        'malformed JSON'=>[['build-result.json'=>'{"schema_version":'],false],
        'JSON scalar'=>[['build-result.json'=>'"not an object"'],false],
        'empty JSON file'=>[['build-result.json'=>''],false],
        'oversized JSON file'=>[['build-result.json'=>str_repeat('x',1024*1024+1)],false],
    ];
    foreach ($zipCases as $label=>[$entries,$shouldPass]) {
        $path=$dir.'/'.bin2hex(random_bytes(4)).'.zip';
        if (!$makeZip($path,$entries)) { ftAssert(false,"fixture created: {$label}"); continue; }
        $fixtureZipPath=$path; $fixtureDownload=['ok'=>true];
        try {
            $got=jenxCollectReadArtifactJson($config,123);
            ftAssert($shouldPass && ($got['request_id']??null)===$requestId,$label);
        } catch (UnexpectedValueException $e) {
            ftAssert(!$shouldPass,$label.' ('.$e->getMessage().')');
        } catch (Throwable $e) {
            ftAssert(false,$label.' unexpected '.get_class($e).': '.$e->getMessage());
        }
        @unlink($path);
    }
    $badZip=$dir.'/corrupt.zip'; file_put_contents($badZip,'not a ZIP');
    $fixtureZipPath=$badZip; $fixtureDownload=['ok'=>true];
    try { jenxCollectReadArtifactJson($config,123); ftAssert(false,'corrupt ZIP rejected'); }
    catch (UnexpectedValueException) { ftAssert(true,'corrupt ZIP rejected'); }
    catch (Throwable $e) { ftAssert(false,'corrupt ZIP unexpected '.get_class($e)); }
    @unlink($badZip);

    $fixtureZipPath=null; $fixtureDownload=['ok'=>false,'error'=>'offline test'];
    try { jenxCollectReadArtifactJson($config,123); ftAssert(false,'download failure surfaced'); }
    catch (RuntimeException $e) { ftAssert(!$e instanceof JenxCollectResultRejected,'download failure is not artifact rejection'); }
    catch (Throwable $e) { ftAssert(false,'download failure wrong exception: '.get_class($e)); }
    @rmdir($dir);
}

/* Safety/source guards */
ftAssert(!str_contains($source,'jenxGithubDispatchWorkflow('),'collector contains no workflow-dispatch call');
ftAssert(str_contains($source,'jenxCollectRequireJson($artifactResponse, \'artifact listing\')'),'artifact listing uses HTTP/JSON validator');
ftAssert(str_contains($source,'jenxBuildStateRecover($requestId, 120)'),'stale dispatch uses 120-second recovery grace');

printf("\n%d tests, %d failure(s).\n",$tests,$failures);
echo "Scope: offline collector function/error-path tests and source guards; no production state, credentials, network, or dispatch used.\n";
exit($failures===0?0:1);
