<?php
declare(strict_types=1);

/**
 * Jen-X collector end-to-end CLI failure-path tests.
 *
 * Run on the Jen-X host:
 *   php -l /var/www/jenx/jenx-collect-cli-failure-test.php
 *   php /var/www/jenx/jenx-collect-cli-failure-test.php
 *
 * Optional explicit source paths:
 *   php jenx-collect-cli-failure-test.php /var/www/jenx/jenx-collect.php /var/www/jenx/jenx-build-state.php
 *
 * The harness copies the collector and real state helper into a private temp
 * directory, redirects the state directory there, and substitutes local
 * config/GitHub stubs. All CLI executions are subprocesses. No live GitHub,
 * credentials, production state, or workflow dispatch is used.
 */

$collectorSource = $argv[1] ?? '/var/www/jenx/jenx-collect.php';
$stateSource = $argv[2] ?? '/var/www/jenx/jenx-build-state.php';

foreach ([$collectorSource, $stateSource] as $sourcePath) {
    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
        fwrite(STDERR, "FATAL: source file not readable: {$sourcePath}\n");
        exit(2);
    }
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "FATAL: PHP ZIP extension is required for these CLI tests.\n");
    exit(2);
}

$root = sys_get_temp_dir() . '/jenx-cli-failure-' . bin2hex(random_bytes(6));
$stateDir = $root . '/state';
if (!mkdir($root, 0700) || !mkdir($stateDir, 0700)) {
    fwrite(STDERR, "FATAL: could not create isolated test directory.\n");
    exit(2);
}
$cleanup = static function (string $path) use (&$cleanup): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . '/' . $entry;
        if (is_dir($child) && !is_link($child)) $cleanup($child);
        else @unlink($child);
    }
    @rmdir($path);
};
$tests = 0;
$failures = 0;
$dispatchCalls = 0;

function cliTestAssert(bool $ok, string $label): void {
    global $tests, $failures;
    ++$tests;
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    if (!$ok) ++$failures;
}
function cliTestWrite(string $path, string $contents): void {
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException("Could not write test fixture: {$path}");
    }
}
function cliTestRun(array $command, array $env): array {
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $spec, $pipes, null, array_merge($_ENV, $env));
    if (!is_resource($proc)) throw new RuntimeException('Could not start CLI subprocess.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return ['exit' => $exit, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
}
function cliTestReadRecord(string $stateDir, string $requestId): array {
    $path = $stateDir . '/' . $requestId . '.json';
    $raw = file_get_contents($path);
    if (!is_string($raw)) throw new RuntimeException("Missing state record {$path}");
    $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($record)) throw new RuntimeException('State record is not an object.');
    return $record;
}

try {
    $collector = file_get_contents($collectorSource);
    $state = file_get_contents($stateSource);
    if (!is_string($collector) || !is_string($state)) {
        throw new RuntimeException('Could not read source files.');
    }

    // Rewrite only the state-directory constant in a temporary copy.
    $statePatched = preg_replace(
        "~const\\s+JENX_BUILD_STATE_DIRECTORY\\s*=\\s*(['\"]).*?\\1\\s*;~",
        "const JENX_BUILD_STATE_DIRECTORY = " . var_export($stateDir, true) . ";",
        $state,
        1,
        $stateReplacements
    );
    if (!is_string($statePatched) || $stateReplacements !== 1) {
        throw new RuntimeException('Could not safely redirect state helper directory.');
    }
    cliTestWrite($root . '/jenx-build-state.php', $statePatched);

    // The collector's production absolute includes are replaced only in its
    // disposable copy. Config and GitHub functions below are deterministic stubs.
    $collectorPatched = str_replace(
        [
            "require_once '/var/www/jenx/jenx-config.php';",
            "require_once '/var/www/jenx/jenx-github.php';",
            "require_once '/var/www/jenx/jenx-build-state.php';",
        ],
        [
            "require_once __DIR__ . '/jenx-config.php';",
            "require_once __DIR__ . '/jenx-github.php';",
            "require_once __DIR__ . '/jenx-build-state.php';",
        ],
        $collector,
        $includeCount
    );
    if (!is_string($collectorPatched) || $includeCount !== 3) {
        throw new RuntimeException('Could not safely redirect collector includes.');
    }
    cliTestWrite($root . '/jenx-collect.php', $collectorPatched);

    $configStub = <<<'PHPSTUB'
<?php
declare(strict_types=1);
function jenxLoadConfig(): array {
    return [
        'GITHUB_WORKFLOW_ID' => '399052069',
        'GITHUB_REPOSITORY' => 'HUMNN-INNOVATIONS/foundd',
        'IMAGE_NAME' => 'ghcr.io/humnn-innovations/foundd',
        'BUILD_ARTIFACT_PREFIX' => 'jenx-build-result-',
    ];
}
PHPSTUB;
    cliTestWrite($root . '/jenx-config.php', $configStub);

    $githubStub = <<<'PHPSTUB'
<?php
declare(strict_types=1);
function jenxTestResponse(array $json = [], int $status = 200, ?string $error = null): array {
    return ['transport_error' => $error, 'http_status' => $status, 'json' => $json];
}
function jenxTestLog(string $event): void {
    $path = getenv('JENX_TEST_CALL_LOG');
    if (is_string($path) && $path !== '') file_put_contents($path, $event . "\n", FILE_APPEND);
}
function jenxGithubListWorkflowRuns(array $config): array {
    jenxTestLog('list_runs');
    $scenario = getenv('JENX_TEST_SCENARIO') ?: '';
    if ($scenario === 'run_api_failure') return jenxTestResponse([], 0, 'offline test transport failure');
    $id = getenv('JENX_TEST_REQUEST_ID') ?: '';
    $tag = getenv('JENX_TEST_RELEASE_TAG') ?: '';
    $title = "Jen-X build {$id} ({$tag}) [TEST ONLY]";
    $run = [
        'id' => 123456789,
        'workflow_id' => 399052069,
        'repository' => ['full_name' => 'HUMNN-INNOVATIONS/foundd'],
        'display_title' => $title,
        'event' => 'workflow_dispatch',
        'head_branch' => 'portable',
        'head_sha' => str_repeat('b', 40),
        'status' => 'completed',
        'conclusion' => 'success',
        'html_url' => 'https://example.invalid/offline-test-run',
    ];
    if ($scenario === 'bad_run_identity') $run['workflow_id'] = 1;
    if ($scenario === 'multiple_runs') return jenxTestResponse(['workflow_runs' => [$run, $run]]);
    return jenxTestResponse(['workflow_runs' => [$run]]);
}
function jenxGithubGetWorkflowRun(array $config, int $runId): array {
    jenxTestLog('get_run');
    return jenxTestResponse([], 500);
}
function jenxGithubListRunArtifacts(array $config, int $runId): array {
    jenxTestLog('list_artifacts');
    $scenario = getenv('JENX_TEST_SCENARIO') ?: '';
    if ($scenario === 'artifact_api_failure') {
        return jenxTestResponse([], 503);
    }
    if ($scenario === 'missing_artifact') return jenxTestResponse(['artifacts' => []]);
    if ($scenario === 'expired_artifact') {
        return jenxTestResponse(['artifacts' => [[
            'id' => 555, 'name' => 'jenx-build-result-123456789', 'expired' => true,
        ]]]);
    }
    if ($scenario === 'unknown_expiry') {
        return jenxTestResponse(['artifacts' => [[
            'id' => 555, 'name' => 'jenx-build-result-123456789',
        ]]]);
    }
    if ($scenario === 'duplicate_artifact') {
        return jenxTestResponse(['artifacts' => [
            ['id' => 555, 'name' => 'jenx-build-result-123456789', 'expired' => false],
            ['id' => 556, 'name' => 'jenx-build-result-123456789', 'expired' => false],
        ]]);
    }
    return jenxTestResponse(['artifacts' => [[
        'id' => 555, 'name' => 'jenx-build-result-123456789', 'expired' => false,
    ]]]);
}
function jenxGithubDownloadArtifactZip(array $config, int $artifactId, string $destination): array {
    jenxTestLog('download_artifact');
    $scenario = getenv('JENX_TEST_SCENARIO') ?: '';
    if ($scenario === 'download_failure') return ['ok' => false, 'error' => 'offline test download failure'];
    $source = getenv('JENX_TEST_ARTIFACT_ZIP');
    if (!is_string($source) || !is_file($source)) return ['ok' => false, 'error' => 'fixture ZIP missing'];
    return copy($source, $destination) ? ['ok' => true] : ['ok' => false, 'error' => 'copy failed'];
}
function jenxGithubDispatchWorkflow(array $config, string $releaseTag, string $requestId, bool $testOnly = false): array {
    jenxTestLog('UNEXPECTED_DISPATCH');
    return ['ok' => false];
}
PHPSTUB;
    cliTestWrite($root . '/jenx-github.php', $githubStub);

    $requestId = '11111111-2222-4333-8444-555555555555';
    $releaseTag = 'v9.9.9-test';
    $commit = str_repeat('a', 40);
    $runId = 123456789;
    $validResult = [
        'schema_version' => 2,
        'request_id' => $requestId,
        'release_tag' => $releaseTag,
        'source_commit' => $commit,
        'image' => 'ghcr.io/humnn-innovations/foundd',
        'digest' => 'sha256:' . str_repeat('c', 64),
        'workflow_run_id' => (string)$runId,
        'test_only' => true,
        'status' => 'success',
    ];

    $makeZip = static function (string $path, string $json): void {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create fixture ZIP.');
        }
        if (!$zip->addFromString('build-result.json', $json)) {
            $zip->close();
            throw new RuntimeException('Could not add fixture result JSON.');
        }
        if (!$zip->close()) throw new RuntimeException('Could not close fixture ZIP.');
    };
    $validZip = $root . '/valid.zip';
    $badIdentityZip = $root . '/bad-identity.zip';
    $corruptZip = $root . '/corrupt.zip';
    $makeZip($validZip, json_encode($validResult, JSON_THROW_ON_ERROR));
    $badIdentity = $validResult;
    $badIdentity['source_commit'] = str_repeat('d', 40);
    $makeZip($badIdentityZip, json_encode($badIdentity, JSON_THROW_ON_ERROR));
    cliTestWrite($corruptZip, 'this is not a ZIP archive');

    $setupPath = $root . '/setup-state.php';
    $setupSource = <<<'SETUP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/jenx-build-state.php';
$id = $argv[1];
$tag = $argv[2];
$commit = $argv[3];
jenxBuildStateCreate($id, $tag, $commit, true);
jenxBuildStateTransition($id, 'DISPATCHING');
jenxBuildStateTransition($id, 'DISPATCHED');
SETUP;
    cliTestWrite($setupPath, $setupSource);

    $callLog = $root . '/calls.log';
    $cases = [
        [
            'name' => 'workflow-run API transport failure leaves state retryable',
            'scenario' => 'run_api_failure',
            'zip' => $validZip,
            'exit' => 2,
            'status' => 'DISPATCHED',
            'must_not' => 'download_artifact',
        ],
        [
            'name' => 'workflow run identity mismatch requires reconciliation, not result rejection',
            'scenario' => 'bad_run_identity',
            'zip' => $validZip,
            'exit' => 2,
            'status' => 'RECONCILIATION_REQUIRED',
            'must_not' => 'download_artifact',
        ],
        [
            'name' => 'multiple matching workflow runs require reconciliation',
            'scenario' => 'multiple_runs',
            'zip' => $validZip,
            'exit' => 2,
            'status' => 'RECONCILIATION_REQUIRED',
            'must_not' => 'download_artifact',
        ],
        [
            'name' => 'artifact listing API failure preserves VERIFYING',
            'scenario' => 'artifact_api_failure',
            'zip' => $validZip,
            'exit' => 2,
            'status' => 'VERIFYING',
            'must_not' => 'download_artifact',
        ],
        [
            'name' => 'missing artifact preserves VERIFYING for later collection',
            'scenario' => 'missing_artifact',
            'zip' => $validZip,
            'exit' => 2,
            'status' => 'VERIFYING',
            'must_not' => 'download_artifact',
        ],
        [
            'name' => 'expired artifact preserves VERIFYING',
            'scenario' => 'expired_artifact',
            'zip' => $validZip,
            'exit' => 2,
            'status' => 'VERIFYING',
            'must_not' => 'download_artifact',
        ],
        [
            'name' => 'unknown artifact expiry fails closed',
            'scenario' => 'unknown_expiry',
            'zip' => $validZip,
            'exit' => 2,
            'status' => 'VERIFYING',
            'must_not' => 'download_artifact',
        ],
        [
            'name' => 'duplicate matching artifacts are not accepted',
            'scenario' => 'duplicate_artifact',
            'zip' => $validZip,
            'exit' => 2,
            'status' => 'VERIFYING',
            'must_not' => 'download_artifact',
        ],
        [
            'name' => 'corrupt ZIP is classified as RESULT_REJECTED',
            'scenario' => 'valid_run_corrupt_zip',
            'zip' => $corruptZip,
            'exit' => 2,
            'status' => 'RESULT_REJECTED',
            'must_not' => null,
        ],
        [
            'name' => 'artifact source-commit mismatch is RESULT_REJECTED',
            'scenario' => 'valid_run_bad_identity_artifact',
            'zip' => $badIdentityZip,
            'exit' => 2,
            'status' => 'RESULT_REJECTED',
            'must_not' => null,
        ],
        [
            'name' => 'artifact download failure remains VERIFYING, not RESULT_REJECTED',
            'scenario' => 'download_failure',
            'zip' => $validZip,
            'exit' => 2,
            'status' => 'VERIFYING',
            'must_not' => null,
        ],
        [
            'name' => 'valid artifact reaches SUCCEEDED',
            'scenario' => 'valid_run_valid_artifact',
            'zip' => $validZip,
            'exit' => 0,
            'status' => 'SUCCEEDED',
            'must_not' => null,
        ],
    ];

    foreach ($cases as $case) {
        // Each case gets its own request UUID and state file.
        $id = sprintf('%08x-%04x-4%03x-8%03x-%012x',
            random_int(1, 0x7fffffff),
            random_int(0, 0xffff),
            random_int(0, 0xfff),
            random_int(0, 0xfff),
            random_int(1, 0xffffffffffff)
        );
        // Keep UUID formatting canonical and use this ID consistently in run title/artifact.
        $tag = $releaseTag;
        $caseResult = $validResult;
        $caseResult['request_id'] = $id;
        $caseResult['release_tag'] = $tag;
        $caseResult['source_commit'] = $commit;
        $caseZip = $case['zip'];
        if ($case['scenario'] === 'valid_run_valid_artifact') {
            $caseZip = $root . '/' . $id . '-valid.zip';
            $makeZip($caseZip, json_encode($caseResult, JSON_THROW_ON_ERROR));
        } elseif ($case['scenario'] === 'valid_run_bad_identity_artifact') {
            $caseResult['source_commit'] = str_repeat('d', 40);
            $caseZip = $root . '/' . $id . '-bad.zip';
            $makeZip($caseZip, json_encode($caseResult, JSON_THROW_ON_ERROR));
        }

        $setup = cliTestRun(
            [PHP_BINARY, $setupPath, $id, $tag, $commit],
            ['JENX_UNUSED' => '1']
        );
        if ($setup['exit'] !== 0) {
            cliTestAssert(false, $case['name'] . ' (state setup failed: ' . trim($setup['stderr']) . ')');
            continue;
        }

        @unlink($callLog);
        $scenario = $case['scenario'];
        // All scenarios except explicit API failure / run identity / artifact
        // metadata cases use a successful correlated workflow response.
        $env = [
            'JENX_TEST_SCENARIO' => $scenario,
            'JENX_TEST_REQUEST_ID' => $id,
            'JENX_TEST_RELEASE_TAG' => $tag,
            'JENX_TEST_ARTIFACT_ZIP' => $caseZip,
            'JENX_TEST_CALL_LOG' => $callLog,
        ];
        $run = cliTestRun([PHP_BINARY, $root . '/jenx-collect.php', $id], $env);
        $record = cliTestReadRecord($stateDir, $id);
        $log = is_file($callLog) ? (string)file_get_contents($callLog) : '';

        cliTestAssert($run['exit'] === $case['exit'], $case['name'] . ' (exit code)');
        cliTestAssert(($record['status'] ?? null) === $case['status'], $case['name'] . ' (persisted status)');
        cliTestAssert(($record['status'] ?? null) !== 'SUCCEEDED' || $case['status'] === 'SUCCEEDED', $case['name'] . ' (no false success)');
        cliTestAssert(!str_contains($log, 'UNEXPECTED_DISPATCH'), $case['name'] . ' (no dispatch call)');
        if ($case['must_not'] !== null) {
            cliTestAssert(!str_contains($log, $case['must_not']), $case['name'] . ' (later processing stopped)');
        }
        if ($case['status'] === 'RESULT_REJECTED') {
            cliTestAssert(($record['error']['code'] ?? null) === 'RESULT_VALIDATION_FAILED', $case['name'] . ' (rejection reason persisted)');
        }
        if ($case['status'] === 'RECONCILIATION_REQUIRED') {
            cliTestAssert(($record['status'] ?? null) !== 'RESULT_REJECTED', $case['name'] . ' (not misclassified as artifact rejection)');
        }
    }

    // A terminal record is an early exit: no GitHub stub is called.
    $terminalId = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    $setup = cliTestRun([PHP_BINARY, $setupPath, $terminalId, $releaseTag, $commit], []);
    if ($setup['exit'] === 0) {
        $statePath = $stateDir . '/' . $terminalId . '.json';
        $terminal = cliTestReadRecord($stateDir, $terminalId);
        $terminal['status'] = 'SUCCEEDED';
        cliTestWrite($statePath, json_encode($terminal, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        @unlink($callLog);
        $run = cliTestRun([PHP_BINARY, $root . '/jenx-collect.php', $terminalId], [
            'JENX_TEST_SCENARIO' => 'valid_run_valid_artifact',
            'JENX_TEST_REQUEST_ID' => $terminalId,
            'JENX_TEST_RELEASE_TAG' => $releaseTag,
            'JENX_TEST_ARTIFACT_ZIP' => $validZip,
            'JENX_TEST_CALL_LOG' => $callLog,
        ]);
        $log = is_file($callLog) ? (string)file_get_contents($callLog) : '';
        cliTestAssert($run['exit'] === 0, 'terminal request exits successfully');
        cliTestAssert($log === '', 'terminal request performs no GitHub API calls');
    } else {
        cliTestAssert(false, 'terminal request setup');
    }

    // A PREPARED record must never call GitHub or dispatch.
    $preparedId = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
    $preparedSetup = <<<'SETUP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/jenx-build-state.php';
jenxBuildStateCreate($argv[1], $argv[2], $argv[3], true);
SETUP;
    cliTestWrite($root . '/setup-prepared.php', $preparedSetup);
    $setup = cliTestRun([PHP_BINARY, $root . '/setup-prepared.php', $preparedId, $releaseTag, $commit], []);
    if ($setup['exit'] === 0) {
        @unlink($callLog);
        $run = cliTestRun([PHP_BINARY, $root . '/jenx-collect.php', $preparedId], [
            'JENX_TEST_SCENARIO' => 'valid_run_valid_artifact',
            'JENX_TEST_REQUEST_ID' => $preparedId,
            'JENX_TEST_RELEASE_TAG' => $releaseTag,
            'JENX_TEST_ARTIFACT_ZIP' => $validZip,
            'JENX_TEST_CALL_LOG' => $callLog,
        ]);
        $log = is_file($callLog) ? (string)file_get_contents($callLog) : '';
        $record = cliTestReadRecord($stateDir, $preparedId);
        cliTestAssert($run['exit'] === 0, 'PREPARED request exits without dispatch');
        cliTestAssert($record['status'] === 'PREPARED', 'PREPARED request state remains unchanged');
        cliTestAssert($log === '', 'PREPARED request performs no GitHub API calls');
    } else {
        cliTestAssert(false, 'PREPARED request setup');
    }

    cliTestAssert(!str_contains($collectorPatched, 'jenxGithubDispatchWorkflow('), 'collector source has no dispatch call');
    printf("\n%d tests, %d failure(s).\n", $tests, $failures);
    echo "Scope: end-to-end CLI subprocesses with the real state helper in a private temporary directory and stubbed GitHub/config. Production state, credentials, network, and workflows were not touched.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: " . get_class($e) . ': ' . $e->getMessage() . "\n");
    $failures++;
} finally {
    $cleanup($root);
}
exit($failures === 0 ? 0 : 1);
