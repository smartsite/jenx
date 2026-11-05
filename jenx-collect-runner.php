<?php
declare(strict_types=1);

/**
 * Jen-X collection runner.
 * Scans persisted build state and invokes the existing single-request
 * collector. It never dispatches or retries a build.
 */
const JENX_RUNNER_STATE_DIRECTORY = '/var/www/jenx/state/builds';
const JENX_RUNNER_COLLECTOR = '/var/www/jenx/jenx-collect.php';
const JENX_RUNNER_LOCK = '/var/www/jenx/state/builds/.collect-runner.lock';
const JENX_RUNNER_SCHEMA_VERSION = 1;
const JENX_RUNNER_DISPATCH_GRACE_SECONDS = 120;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

function jenxRunnerLog(string $level, string $message): void
{
    printf("[%s] %s %s\n", gmdate('c'), $level, $message);
}

function jenxRunnerReadRecord(string $path, string $requestId): ?array
{
    if (is_link($path) || !is_file($path)) {
        jenxRunnerLog('WARN', "Skipping unsafe or missing state file: {$path}");
        return null;
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        jenxRunnerLog('WARN', "Could not read state file for {$requestId}");
        return null;
    }

    try {
        $record = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        jenxRunnerLog('ERROR', "Invalid JSON for {$requestId}: " . $exception->getMessage());
        return null;
    }

    if (
        !is_array($record) ||
        ($record['schema_version'] ?? null) !== JENX_RUNNER_SCHEMA_VERSION ||
        ($record['request_id'] ?? null) !== $requestId ||
        !is_string($record['status'] ?? null)
    ) {
        jenxRunnerLog('ERROR', "State record failed scheduling validation: {$requestId}");
        return null;
    }

    return $record;
}

function jenxRunnerIsEligible(array $record, int $now): bool
{
    $status = $record['status'];

    if (in_array($status, [
        'PREPARED', 'SUCCEEDED', 'DISPATCH_REJECTED', 'BUILD_FAILED', 'RESULT_REJECTED',
    ], true)) {
        return false;
    }

    if (!in_array($status, [
        'DISPATCHING', 'DISPATCHED', 'RUNNING', 'VERIFYING', 'RECONCILIATION_REQUIRED',
    ], true)) {
        return false;
    }

    // Respect the collector's 120-second dispatch grace period. Malformed or
    // future timestamps are left for the collector's fail-closed recovery.
    if ($status === 'DISPATCHING') {
        $updatedAt = strtotime((string) ($record['updated_at'] ?? ''));
        if (
            $updatedAt !== false &&
            $updatedAt <= $now &&
            ($now - $updatedAt) < JENX_RUNNER_DISPATCH_GRACE_SECONDS
        ) {
            return false;
        }
    }

    return true;
}

function jenxRunnerCollect(string $requestId): int
{
    // Array-form proc_open avoids shell parsing; requestId has already been
    // extracted from a UUID-only filename and validated against the record.
    $command = [PHP_BINARY, JENX_RUNNER_COLLECTOR, $requestId];
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, null, null, [
        'bypass_shell' => true,
    ]);

    if (!is_resource($process)) {
        jenxRunnerLog('ERROR', "Could not start collector for {$requestId}");
        return 127;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if (is_string($stdout) && trim($stdout) !== '') {
        foreach (preg_split('/\R/', trim($stdout)) as $line) {
            jenxRunnerLog('COLLECTOR', "{$requestId}: {$line}");
        }
    }
    if (is_string($stderr) && trim($stderr) !== '') {
        foreach (preg_split('/\R/', trim($stderr)) as $line) {
            jenxRunnerLog('COLLECTOR-ERR', "{$requestId}: {$line}");
        }
    }

    jenxRunnerLog(
        $exitCode === 0 ? 'INFO' : 'WARN',
        "Collector exit code {$exitCode} for {$requestId}"
    );
    return $exitCode;
}

/* Main */
if (!is_dir(JENX_RUNNER_STATE_DIRECTORY) || is_link(JENX_RUNNER_STATE_DIRECTORY)) {
    fwrite(STDERR, "ERROR: Build state directory is missing or unsafe.\n");
    exit(1);
}
if (!is_file(JENX_RUNNER_COLLECTOR) || is_link(JENX_RUNNER_COLLECTOR)) {
    fwrite(STDERR, "ERROR: Collector script is missing or unsafe.\n");
    exit(1);
}

$lockHandle = fopen(JENX_RUNNER_LOCK, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "ERROR: Could not open runner lock file.\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    jenxRunnerLog('INFO', 'Another collection pass is already running; skipping this tick.');
    exit(0);
}

try {
    $paths = glob(JENX_RUNNER_STATE_DIRECTORY . '/*.json');
    if ($paths === false) {
        throw new RuntimeException('Could not scan the build state directory.');
    }

    sort($paths, SORT_STRING);
    $now = time();
    $processed = 0;
    $eligible = 0;
    $nonzeroExit = 0;

    foreach ($paths as $path) {
        $filename = basename($path);
        if (!preg_match(
            '/^([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\.json$/i',
            $filename,
            $matches
        )) {
            continue;
        }

        $requestId = strtolower($matches[1]);
        $record = jenxRunnerReadRecord($path, $requestId);
        if ($record === null || !jenxRunnerIsEligible($record, $now)) {
            continue;
        }

        $eligible++;
        $processed++;
        if (jenxRunnerCollect($requestId) !== 0) {
            // Exit 2 commonly means "not ready yet". Never rewrite state here.
            $nonzeroExit++;
        }
    }

    jenxRunnerLog(
        'INFO',
        sprintf(
            'Collection pass finished: eligible=%d processed=%d nonzero_exit=%d',
            $eligible,
            $processed,
            $nonzeroExit
        )
    );
} catch (Throwable $exception) {
    jenxRunnerLog('ERROR', get_class($exception) . ': ' . $exception->getMessage());
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

// A not-yet-ready artifact is a normal polling outcome; details are in the journal.
exit(0);
