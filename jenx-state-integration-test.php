<?php
declare(strict_types=1);

/**
 * Jen-X isolated filesystem/CLI integration tests.
 *
 * Uses copies of the collector and state helper in a temporary sandbox.
 * GitHub and configuration dependencies are stubbed. No production state,
 * credentials, network requests, or workflow dispatches are used.
 *
 * Usage:
 *   php jenx-state-integration-test.php
 *   php jenx-state-integration-test.php /var/www/jenx/jenx-collect.php /var/www/jenx/jenx-build-state.php
 */

$collectorSourcePath = $argv[1] ?? '/var/www/jenx/jenx-collect.php';
$stateSourcePath = $argv[2] ?? '/var/www/jenx/jenx-build-state.php';
$tests = 0;
$failures = 0;
$sandbox = null;

function integrationAssert(bool $condition, string $message): void
{
    global $tests, $failures;
    $tests++;

    if ($condition) {
        echo "PASS: {$message}\n";
    } else {
        $failures++;
        echo "FAIL: {$message}\n";
    }
}

function integrationExpectException(
    callable $callback,
    string $class,
    string $label
): void {
    try {
        $callback();
        integrationAssert(false, $label . ' (no exception)');
    } catch (Throwable $exception) {
        integrationAssert(
            $exception instanceof $class,
            $label . ' (' . get_class($exception) . ')'
        );
    }
}

function integrationRunCli(string $script, string $requestId): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $script, $requestId],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start isolated collector subprocess.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $stdout = is_string($stdout) ? $stdout : '';
    $stderr = is_string($stderr) ? $stderr : '';

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'combined' => $stdout . $stderr,
    ];
}

function integrationMakeStale(string $requestId, int $ageSeconds = 180): void
{
    $lock = jenxBuildStateLock($requestId);

    try {
        $record = jenxBuildStateReadLocked($requestId);
        $record['updated_at'] = gmdate('c', time() - $ageSeconds);
        jenxBuildStateWriteLocked($requestId, $record);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function integrationCleanup(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    $items = scandir($path);

    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                integrationCleanup($path . DIRECTORY_SEPARATOR . $item);
            }
        }
    }

    @rmdir($path);
}

try {
    if (
        !is_readable($collectorSourcePath) ||
        !is_readable($stateSourcePath)
    ) {
        throw new RuntimeException(
            'Cannot read collector/state helper. Supply their paths as arguments if needed.'
        );
    }

    $collectorSource = file_get_contents($collectorSourcePath);
    $stateSource = file_get_contents($stateSourcePath);

    if (!is_string($collectorSource) || !is_string($stateSource)) {
        throw new RuntimeException('Could not read source files.');
    }

    $sandbox = sys_get_temp_dir()
        . '/jenx-integration-' . bin2hex(random_bytes(8));

    $appDir = $sandbox . '/app';
    $stateDir = $sandbox . '/state/builds';

    if (!mkdir($appDir, 0700, true) || !mkdir($stateDir, 0700, true)) {
        throw new RuntimeException('Could not create private temporary sandbox.');
    }

    chmod($sandbox, 0700);
    chmod($appDir, 0700);
    chmod(dirname($stateDir), 0700);
    chmod($stateDir, 0700);

    // Redirect only the copied state helper to the temporary directory.
    $pattern = "~const\\s+JENX_BUILD_STATE_DIRECTORY\\s*=\\s*'[^']*'\\s*;~";
    $replacement = 'const JENX_BUILD_STATE_DIRECTORY = '
        . var_export($stateDir, true) . ';';

    $patchedState = preg_replace(
        $pattern,
        $replacement,
        $stateSource,
        1,
        $replacementCount
    );

    if (!is_string($patchedState) || $replacementCount !== 1) {
        throw new RuntimeException('Could not safely redirect state helper directory.');
    }

    $stateCopy = $appDir . '/jenx-build-state.php';
    $collectorCopy = $appDir . '/jenx-collect.php';
    $configCopy = $appDir . '/jenx-config.php';
    $githubCopy = $appDir . '/jenx-github.php';

    if (file_put_contents($stateCopy, $patchedState) === false) {
        throw new RuntimeException('Could not write isolated state helper.');
    }

    // Fixed, fake configuration; no production environment file is read.
    $configStub = <<<'CONFIG'
<?php
declare(strict_types=1);

function jenxLoadConfig(): array {
    return [
        'GITHUB_TOKEN' => 'OFFLINE-TEST-NOT-A-REAL-TOKEN',
        'GITHUB_REPOSITORY' => 'HUMNN-INNOVATIONS/foundd',
        'GITHUB_WORKFLOW_ID' => '399052069',
        'GITHUB_API_VERSION' => '2022-11-28',
        'IMAGE_NAME' => 'ghcr.io/humnn-innovations/foundd',
        'BUILD_BRANCH' => 'portable',
        'BUILD_ARTIFACT_PREFIX' => 'jenx-build-result-',
    ];
}
CONFIG;

    // Every GitHub function available here is offline and logs its invocation.
    $githubStub = <<<'GITHUB'
<?php
declare(strict_types=1);

function jenxIntegrationLogGithubCall(string $name): void {
    file_put_contents(
        __DIR__ . '/github-calls.log',
        $name . "\n",
        FILE_APPEND | LOCK_EX
    );
}

function jenxGithubListWorkflowRuns(array $config): array {
    jenxIntegrationLogGithubCall('list_workflow_runs');
    return [
        'transport_error' => null,
        'http_status' => 200,
        'json' => ['workflow_runs' => []],
    ];
}

function jenxGithubGetWorkflowRun(array $config, int $runId): array {
    jenxIntegrationLogGithubCall('get_workflow_run');
    return [
        'transport_error' => null,
        'http_status' => 404,
        'json' => null,
    ];
}
GITHUB;

    file_put_contents($configCopy, $configStub);
    file_put_contents($githubCopy, $githubStub);

    // Patch includes in the collector copy only.
    $replacements = [
        "require_once '/var/www/jenx/jenx-config.php';"
            => 'require_once ' . var_export($configCopy, true) . ';',
        "require_once '/var/www/jenx/jenx-github.php';"
            => 'require_once ' . var_export($githubCopy, true) . ';',
        "require_once '/var/www/jenx/jenx-build-state.php';"
            => 'require_once ' . var_export($stateCopy, true) . ';',
    ];

    foreach ($replacements as $needle => $replacement) {
        if (!str_contains($collectorSource, $needle)) {
            throw new RuntimeException(
                'Collector include layout differed from expected; refusing to patch it.'
            );
        }

        $collectorSource = str_replace($needle, $replacement, $collectorSource);
    }

    if (file_put_contents($collectorCopy, $collectorSource) === false) {
        throw new RuntimeException('Could not write isolated collector.');
    }

    // Load the actual copied state helper for real filesystem assertions.
    require $stateCopy;

    $ids = [
        'prepared' => '11111111-1111-4111-8111-111111111111',
        'fresh' => '22222222-2222-4222-8222-222222222222',
        'stale_direct' => '33333333-3333-4333-8333-333333333333',
        'terminal' => '44444444-4444-4444-8444-444444444444',
        'verifying' => '55555555-5555-4555-8555-555555555555',
        'stale_cli' => '66666666-6666-4666-8666-666666666666',
        'unknown' => '99999999-9999-4999-8999-999999999999',
    ];

    $commit = str_repeat('a', 40);

    $create = static fn(string $id): array =>
        jenxBuildStateCreate($id, 'v9.9.9-test', $commit, true);

    // A. Persistence, duplicate protection, transitions and update restrictions.
    $prepared = $create($ids['prepared']);
    integrationAssert($prepared['status'] === 'PREPARED', 'state helper creates PREPARED record');

    $preparedPath = jenxBuildStateRecordPath($ids['prepared']);
    integrationAssert(is_file($preparedPath), 'record is persisted to isolated filesystem');
    integrationAssert(
        (fileperms($preparedPath) & 0777) === 0640,
        'persisted state file has mode 0640'
    );
    integrationAssert(
        jenxBuildStateGet($ids['prepared'])['status'] === 'PREPARED',
        'state helper reads persisted record under lock'
    );

    integrationExpectException(
        fn() => $create($ids['prepared']),
        RuntimeException::class,
        'duplicate UUID cannot overwrite existing record'
    );
    integrationExpectException(
        fn() => jenxBuildStateTransition($ids['prepared'], 'RUNNING'),
        RuntimeException::class,
        'invalid PREPARED to RUNNING transition is rejected'
    );
    integrationAssert(
        jenxBuildStateGet($ids['prepared'])['status'] === 'PREPARED',
        'rejected transition leaves persisted state unchanged'
    );
    integrationExpectException(
        fn() => jenxBuildStateTransition(
            $ids['prepared'],
            'DISPATCHING',
            ['request_id' => 'attacker']
        ),
        InvalidArgumentException::class,
        'protected identity fields cannot be updated'
    );
    integrationExpectException(
        fn() => jenxBuildStateRecover($ids['prepared'], 29),
        InvalidArgumentException::class,
        'recovery rejects grace period below 30 seconds'
    );

    // B. Fresh DISPATCHING records must not be recovered early.
    $create($ids['fresh']);
    jenxBuildStateTransition($ids['fresh'], 'DISPATCHING');
    $freshBefore = jenxBuildStateGet($ids['fresh']);
    $freshAfter = jenxBuildStateRecover($ids['fresh'], 120);

    integrationAssert(
        $freshAfter['status'] === 'DISPATCHING',
        'fresh DISPATCHING record is not recovered early'
    );
    integrationAssert(
        $freshAfter['updated_at'] === $freshBefore['updated_at'],
        'fresh recovery does not rewrite updated_at'
    );

    // C. Stale recovery and repeat-call idempotence.
    $create($ids['stale_direct']);
    jenxBuildStateTransition($ids['stale_direct'], 'DISPATCHING');
    integrationMakeStale($ids['stale_direct']);

    $recovered = jenxBuildStateRecover($ids['stale_direct'], 120);
    integrationAssert(
        $recovered['status'] === 'RECONCILIATION_REQUIRED',
        'stale DISPATCHING moves to RECONCILIATION_REQUIRED'
    );
    integrationAssert(
        ($recovered['error']['code'] ?? null) === 'DISPATCH_OUTCOME_UNKNOWN',
        'stale recovery records DISPATCH_OUTCOME_UNKNOWN'
    );

    $recoveredAgain = jenxBuildStateRecover($ids['stale_direct'], 120);
    integrationAssert(
        $recoveredAgain['status'] === 'RECONCILIATION_REQUIRED',
        'repeated recovery is idempotent'
    );
    integrationAssert(
        $recoveredAgain['updated_at'] === $recovered['updated_at'],
        'repeated recovery does not rewrite recovered record'
    );

    // D. The success gate must reject missing result metadata.
    $create($ids['verifying']);
    jenxBuildStateTransition($ids['verifying'], 'DISPATCHING');
    jenxBuildStateTransition($ids['verifying'], 'DISPATCHED');
    jenxBuildStateTransition($ids['verifying'], 'VERIFYING');

    integrationExpectException(
        fn() => jenxBuildStateTransition($ids['verifying'], 'SUCCEEDED'),
        RuntimeException::class,
        'SUCCEEDED without valid result is rejected'
    );
    integrationAssert(
        jenxBuildStateGet($ids['verifying'])['status'] === 'VERIFYING',
        'failed success gate leaves state in VERIFYING'
    );

    // E. Actual collector CLI paths in isolated subprocesses.
    $unknownRun = integrationRunCli($collectorCopy, $ids['unknown']);
    integrationAssert(
        $unknownRun['exit_code'] !== 0,
        'unknown UUID CLI request exits nonzero'
    );
    integrationAssert(
        !is_file($sandbox . '/app/github-calls.log'),
        'unknown UUID does not call GitHub stub'
    );

    $preparedRun = integrationRunCli($collectorCopy, $ids['prepared']);
    integrationAssert(
        $preparedRun['exit_code'] === 0
            && str_contains($preparedRun['combined'], 'Status: PREPARED'),
        'PREPARED CLI request exits cleanly with PREPARED status'
    );
    integrationAssert(
        !is_file($sandbox . '/app/github-calls.log'),
        'PREPARED CLI request does not call GitHub stub'
    );

    $freshRun = integrationRunCli($collectorCopy, $ids['fresh']);
    integrationAssert(
        $freshRun['exit_code'] === 2
            && str_contains($freshRun['combined'], 'within its grace period'),
        'fresh DISPATCHING CLI request exits with retry-later status'
    );
    integrationAssert(
        !is_file($sandbox . '/app/github-calls.log'),
        'fresh DISPATCHING CLI request does not call GitHub stub'
    );

    $create($ids['stale_cli']);
    jenxBuildStateTransition($ids['stale_cli'], 'DISPATCHING');
    integrationMakeStale($ids['stale_cli']);

    $staleRun = integrationRunCli($collectorCopy, $ids['stale_cli']);
    $stalePersisted = jenxBuildStateGet($ids['stale_cli']);

    integrationAssert(
        $staleRun['exit_code'] === 2
            && str_contains($staleRun['combined'], 'No matching workflow run'),
        'stale DISPATCHING CLI request recovers then reconciles without dispatch'
    );
    integrationAssert(
        $stalePersisted['status'] === 'RECONCILIATION_REQUIRED',
        'CLI stale recovery persists to isolated state file'
    );
    integrationAssert(
        is_file($sandbox . '/app/github-calls.log')
            && str_contains(
                (string) file_get_contents($sandbox . '/app/github-calls.log'),
                'list_workflow_runs'
            ),
        'stale reconciliation calls only the offline GitHub stub'
    );

    // Terminal states must short-circuit before GitHub reconciliation.
    $create($ids['terminal']);
    jenxBuildStateTransition($ids['terminal'], 'DISPATCHING');
    jenxBuildStateTransition($ids['terminal'], 'DISPATCH_REJECTED');

    $callsBefore = is_file($sandbox . '/app/github-calls.log')
        ? file_get_contents($sandbox . '/app/github-calls.log')
        : '';

    $terminalRun = integrationRunCli($collectorCopy, $ids['terminal']);

    $callsAfter = is_file($sandbox . '/app/github-calls.log')
        ? file_get_contents($sandbox . '/app/github-calls.log')
        : '';

    integrationAssert(
        $terminalRun['exit_code'] === 0
            && str_contains($terminalRun['combined'], 'terminal state'),
        'terminal CLI request returns its saved terminal state'
    );
    integrationAssert(
        $callsBefore === $callsAfter,
        'terminal CLI request does not call GitHub stub'
    );

    echo "\n{$tests} tests, {$failures} failure(s).\n";
    echo "Scope: real state helper + isolated filesystem + collector CLI; GitHub calls stubbed offline.\n";
    echo "Production state files, credentials, and collector files were not modified.\n";
} catch (Throwable $exception) {
    $failures++;
    fwrite(
        STDERR,
        'HARNESS ERROR: ' . get_class($exception) . ': '
        . $exception->getMessage() . "\n"
    );
} finally {
    if (
        is_string($sandbox)
        && $sandbox !== ''
        && str_starts_with($sandbox, sys_get_temp_dir() . '/jenx-integration-')
    ) {
        integrationCleanup($sandbox);
    }
}

exit($failures === 0 ? 0 : 1);
