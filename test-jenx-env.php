<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/jenx-env-test-' . bin2hex(random_bytes(5));
$envDirectory = $root . '/environments';
$buildDirectory = $root . '/builds';
mkdir($buildDirectory, 0750, true);
putenv('JENX_ENV_STATE_DIRECTORY=' . $envDirectory);
putenv('JENX_BUILD_STATE_DIRECTORY=' . $buildDirectory);
require_once __DIR__ . '/jenx-env-state.php';

$passed = 0;
$failed = 0;
function check(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$message}\n";
    } else {
        $failed++;
        echo "FAIL: {$message}\n";
    }
}
function expectException(callable $callback, string $class, string $message): void
{
    try {
        $callback();
        check(false, $message);
    } catch (Throwable $exception) {
        check($exception instanceof $class, $message . ' (' . get_class($exception) . ')');
    }
}

$digestA = 'sha256:' . str_repeat('a', 64);
$digestB = 'sha256:' . str_repeat('b', 64);

check(jenxEnvValidateName('DEV') === 'dev', 'environment names normalise to lowercase');
expectException(fn() => jenxEnvValidateName('production'), InvalidArgumentException::class, 'reject unknown environment');
expectException(fn() => jenxEnvValidateDigest('sha256:bad'), InvalidArgumentException::class, 'reject malformed digest');
check(jenxEnvReadState('dev') === null, 'missing environment state is not initialised');

$missingActions = jenxEnvEligibility('dev');
check($missingActions['initialized'] === false, 'eligibility reports uninitialised environment');
check($missingActions['actions']['deploy']['available'] === false, 'deploy eligibility requires a candidate digest');

$requestId = jenxEnvUuid();
$build = [
    'schema_version' => 1,
    'request_id' => $requestId,
    'status' => 'SUCCEEDED',
    'release_tag' => 'v9.9.9',
    'expected_source_commit' => str_repeat('c', 40),
    'test_only' => false,
    'result' => [
        'status' => 'success',
        'request_id' => $requestId,
        'release_tag' => 'v9.9.9',
        'source_commit' => str_repeat('c', 40),
        'image' => 'ghcr.io/humnn-innovations/foundd',
        'digest' => $digestA,
        'workflow_run_id' => '123456',
        'test_only' => false,
    ],
];
file_put_contents($buildDirectory . '/' . $requestId . '.json', json_encode($build, JSON_THROW_ON_ERROR));
check(jenxEnvFindValidatedBuild($digestA) !== null, 'accept a matching validated non-test build');
check(jenxEnvEligibility('dev', $digestA)['actions']['deploy']['available'] === true, 'eligible digest makes deploy available for an idle environment');

$testBuildId = jenxEnvUuid();
$testBuild = $build;
$testBuild['request_id'] = $testBuildId;
$testBuild['result']['request_id'] = $testBuildId;
$testBuild['test_only'] = true;
$testBuild['result']['digest'] = $digestB;
$testBuild['result']['test_only'] = true;
file_put_contents($buildDirectory . '/' . $testBuildId . '.json', json_encode($testBuild, JSON_THROW_ON_ERROR));
check(jenxEnvFindValidatedBuild($digestB) === null, 'reject test-only build as deployment candidate');

$dev = jenxEnvDefaultState('dev');
$devDeploymentId = jenxEnvUuid();
$dev['desired'] = ['image' => 'ghcr.io/humnn-innovations/foundd', 'digest' => $digestA, 'source_commit' => str_repeat('c', 40), 'release_tag' => 'v9.9.9'];
$dev['observed'] = ['image' => 'ghcr.io/humnn-innovations/foundd', 'digest' => $digestA, 'container_state' => 'running', 'health' => 'healthy', 'observed_at' => jenxEnvTimestamp()];
$dev['current_deployment_id'] = $devDeploymentId;
$dev['deployments'] = [[
    'deployment_id' => $devDeploymentId,
    'operation_id' => jenxEnvUuid(),
    'digest' => $digestA,
    'image' => 'ghcr.io/humnn-innovations/foundd',
    'source_commit' => str_repeat('c', 40),
    'release_tag' => 'v9.9.9',
    'verified_healthy' => true,
    'deployed_at' => jenxEnvTimestamp(),
]];
jenxEnvWriteState('dev', $dev);
$devRead = jenxEnvReadState('dev');
check($devRead !== null && jenxEnvIsHealthy($devRead), 'atomic state persistence round-trips a healthy deployment');
check(jenxEnvEligibility('uat', null, 'dev')['actions']['promote']['available'] === true, 'healthy source makes promotion eligible for an idle target');

$uat = jenxEnvDefaultState('uat');
$oldId = jenxEnvUuid();
$currentId = jenxEnvUuid();
$uat['observed'] = ['image' => 'ghcr.io/humnn-innovations/foundd', 'digest' => $digestB, 'container_state' => 'running', 'health' => 'healthy', 'observed_at' => jenxEnvTimestamp()];
$uat['current_deployment_id'] = $currentId;
$uat['deployments'] = [
    ['deployment_id' => $currentId, 'operation_id' => jenxEnvUuid(), 'digest' => $digestB, 'image' => 'ghcr.io/humnn-innovations/foundd', 'source_commit' => str_repeat('d', 40), 'release_tag' => 'v9.9.8', 'verified_healthy' => true, 'deployed_at' => jenxEnvTimestamp()],
    ['deployment_id' => $oldId, 'operation_id' => jenxEnvUuid(), 'digest' => $digestA, 'image' => 'ghcr.io/humnn-innovations/foundd', 'source_commit' => str_repeat('c', 40), 'release_tag' => 'v9.9.9', 'verified_healthy' => true, 'deployed_at' => jenxEnvTimestamp()],
];
jenxEnvWriteState('uat', $uat);
$rollback = jenxEnvEligibility('uat');
check($rollback['actions']['rollback']['available'] === true && $rollback['rollback_candidate']['deployment_id'] === $oldId, 'rollback selects prior verified healthy deployment');

$operation = jenxEnvCreateOperation('deploy', 'dev', null, $digestA);
check($operation['status'] === 'PREPARED' && jenxEnvReadOperation($operation['operation_id']) !== null, 'operation intent is durably persisted');
expectException(fn() => jenxEnvTransitionOperation($operation, 'SUCCEEDED'), DomainException::class, 'cannot succeed without verification');
$operation = jenxEnvTransitionOperation($operation, 'APPLYING');
$operation = jenxEnvTransitionOperation($operation, 'VERIFYING');
$operation = jenxEnvTransitionOperation($operation, 'SUCCEEDED', ['verification' => ['image_digest_matches' => true, 'application_healthy' => true]]);
check($operation['finished_at'] !== null, 'successful operation requires and records both verification checks');
expectException(fn() => jenxEnvTransitionOperation($operation, 'APPLYING'), DomainException::class, 'terminal operation cannot transition');

$lock = jenxEnvAcquireLock('prod');
check(is_resource($lock), 'per-environment lock can be acquired');
$secondLock = jenxEnvAcquireLock('prod');
check($secondLock === false, 'concurrent lock acquisition is rejected');
if (is_resource($lock)) {
    flock($lock, LOCK_UN);
    fclose($lock);
}

// Ensure the CLI status command works against an isolated, empty state directory.
$cliCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/jenx-env.php') . ' status --json';
$output = [];
$exitCode = 0;
exec('JENX_ENV_STATE_DIRECTORY=' . escapeshellarg($envDirectory) . ' JENX_BUILD_STATE_DIRECTORY=' . escapeshellarg($buildDirectory) . ' ' . $cliCommand . ' 2>&1', $output, $exitCode);
$decoded = json_decode(implode("\n", $output), true);
check($exitCode === 0 && is_array($decoded) && count($decoded['environments'] ?? []) === 3, 'CLI status --json returns all three environments');

// CLI mutation is deliberately fail-closed before the adapter exists.
$deployCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/jenx-env.php') . ' deploy dev ' . escapeshellarg($digestA) . ' --json';
$output = [];
exec('JENX_ENV_STATE_DIRECTORY=' . escapeshellarg($envDirectory) . ' JENX_BUILD_STATE_DIRECTORY=' . escapeshellarg($buildDirectory) . ' ' . $deployCommand . ' 2>&1', $output, $exitCode);
$decoded = json_decode(implode("\n", $output), true);
check($exitCode === 7 && ($decoded['error']['code'] ?? null) === 'DEPLOYMENT_ADAPTER_UNAVAILABLE', 'mutating CLI fails closed without changing environment state');
$prodCommand = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/jenx-env.php') . ' deploy prod ' . escapeshellarg($digestA) . ' --json';
$output = [];
exec('JENX_ENV_STATE_DIRECTORY=' . escapeshellarg($envDirectory) . ' JENX_BUILD_STATE_DIRECTORY=' . escapeshellarg($buildDirectory) . ' ' . $prodCommand . ' 2>&1', $output, $exitCode);
$decoded = json_decode(implode("\n", $output), true);
check($exitCode === 2 && ($decoded['error']['code'] ?? null) === 'PROD_CONFIRMATION_REQUIRED', 'PROD mutation requires explicit confirmation');
check(jenxEnvReadState('uat') !== null, 'existing environment state remains intact after failed adapter preflight');

// Cleanup temporary test state only.
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($root);

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
