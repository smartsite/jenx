<?php

declare(strict_types=1);

/**
 * Jen-X Build State
 *
 * Persistent request records for the Build/Collect workflow.
 * This file performs no network requests and never dispatches a workflow.
 */

const JENX_BUILD_STATE_SCHEMA_VERSION = 1;
const JENX_BUILD_STATE_DIRECTORY = '/var/www/jenx/state/builds';

const JENX_BUILD_STATES = [
    'PREPARED',
    'DISPATCHING',
    'DISPATCHED',
    'RUNNING',
    'VERIFYING',
    'SUCCEEDED',
    'DISPATCH_REJECTED',
    'BUILD_FAILED',
    'RESULT_REJECTED',
    'RECONCILIATION_REQUIRED',
];

/**
 * Valid transitions. Terminal states have no outgoing transitions.
 * RECONCILIATION_REQUIRED may resume only after external evidence resolves
 * the uncertain outcome.
 */
const JENX_BUILD_STATE_TRANSITIONS = [
    'PREPARED' => [
        'DISPATCHING',
    ],
    'DISPATCHING' => [
        'DISPATCHED',
        'DISPATCH_REJECTED',
        'RECONCILIATION_REQUIRED',
    ],
    'DISPATCHED' => [
        'RUNNING',
        'VERIFYING',
        'BUILD_FAILED',
        'RECONCILIATION_REQUIRED',
    ],
    'RUNNING' => [
        'VERIFYING',
        'BUILD_FAILED',
        'RECONCILIATION_REQUIRED',
    ],
    'VERIFYING' => [
        'SUCCEEDED',
        'RESULT_REJECTED',
        'RECONCILIATION_REQUIRED',
    ],
    'RECONCILIATION_REQUIRED' => [
        'DISPATCHED',
        'RUNNING',
        'VERIFYING',
        'DISPATCH_REJECTED',
        'BUILD_FAILED',
    ],
    'SUCCEEDED' => [],
    'DISPATCH_REJECTED' => [],
    'BUILD_FAILED' => [],
    'RESULT_REJECTED' => [],
];


/** Validate and normalise a request UUID. */
function jenxBuildStateValidateRequestId(string $requestId): string
{
    if (!preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $requestId
    )) {
        throw new InvalidArgumentException('Build request ID must be a UUID.');
    }

    return strtolower($requestId);
}


/** Resolve a record path using only a validated UUID. */
function jenxBuildStateRecordPath(string $requestId): string
{
    return JENX_BUILD_STATE_DIRECTORY . '/'
        . jenxBuildStateValidateRequestId($requestId) . '.json';
}


/** Ensure the configured state directory is safe to use. */
function jenxBuildStateEnsureDirectory(): void
{
    $directory = JENX_BUILD_STATE_DIRECTORY;

    if (is_link($directory)) {
        throw new RuntimeException('Build state directory must not be a symlink.');
    }

    if (!is_dir($directory)) {
        throw new RuntimeException(
            'Build state directory does not exist; create it and configure permissions first.'
        );
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('Build state directory is not writable.');
    }
}


/**
 * Acquire an exclusive per-request lock.
 * The caller must release the returned handle in a finally block.
 */
function jenxBuildStateLock(string $requestId)
{
    jenxBuildStateEnsureDirectory();

    $requestId = jenxBuildStateValidateRequestId($requestId);
    $lockPath = JENX_BUILD_STATE_DIRECTORY . '/.' . $requestId . '.lock';

    if (is_link($lockPath)) {
        throw new RuntimeException('Build state lock must not be a symlink.');
    }

    $handle = fopen($lockPath, 'c');

    if ($handle === false) {
        throw new RuntimeException('Could not open build state lock.');
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Could not lock build state record.');
    }

    return $handle;
}


/** Read and validate an existing record. Caller must hold its lock. */
function jenxBuildStateReadLocked(string $requestId): array
{
    $path = jenxBuildStateRecordPath($requestId);

    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('Build state record does not exist or is unsafe.');
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Could not read build state record.');
    }

    try {
        $record = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(
            'Build state record contains invalid JSON.',
            0,
            $exception
        );
    }

    if (!is_array($record)) {
        throw new RuntimeException('Build state record has an invalid structure.');
    }

    if (
        ($record['schema_version'] ?? null) !== JENX_BUILD_STATE_SCHEMA_VERSION ||
        ($record['request_id'] ?? null) !== $requestId ||
        !in_array($record['status'] ?? null, JENX_BUILD_STATES, true)
    ) {
        throw new RuntimeException('Build state record failed validation.');
    }

    return $record;
}


/** Atomically replace a record. Caller must hold its lock. */
function jenxBuildStateWriteLocked(string $requestId, array $record): void
{
    $path = jenxBuildStateRecordPath($requestId);
    $directory = JENX_BUILD_STATE_DIRECTORY;

    if (is_link($path)) {
        throw new RuntimeException('Refusing to replace a symlinked build state record.');
    }

    $json = json_encode(
        $record,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";

    $temporaryPath = tempnam($directory, '.jenx-state-');

    if ($temporaryPath === false) {
        throw new RuntimeException('Could not create temporary state file.');
    }

    try {
        if (file_put_contents($temporaryPath, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('Could not write complete build state record.');
        }

        if (!chmod($temporaryPath, 0640)) {
            throw new RuntimeException('Could not set build state file permissions.');
        }

        if (!rename($temporaryPath, $path)) {
            throw new RuntimeException('Could not atomically save build state record.');
        }
    } finally {
        if (file_exists($temporaryPath)) {
            @unlink($temporaryPath);
        }
    }
}


/** Create a new build request record. Existing UUIDs are never overwritten. */
function jenxBuildStateCreate(
    string $requestId,
    string $releaseTag,
    string $expectedSourceCommit,
    bool $testOnly
): array {
    $requestId = jenxBuildStateValidateRequestId($requestId);

    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $releaseTag)) {
        throw new InvalidArgumentException('Release tag format is invalid.');
    }

    if (!preg_match('/^[0-9a-f]{40}$/i', $expectedSourceCommit)) {
        throw new InvalidArgumentException('Expected source commit must be a full 40-character SHA.');
    }

    $lock = jenxBuildStateLock($requestId);

    try {
        $path = jenxBuildStateRecordPath($requestId);

        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('A build request with this UUID already exists.');
        }

        $now = gmdate('c');

        $record = [
            'schema_version' => JENX_BUILD_STATE_SCHEMA_VERSION,
            'request_id' => $requestId,
            'operation' => 'build',
            'release_tag' => $releaseTag,
            'test_only' => $testOnly,
            'source_branch' => 'portable',
            'expected_source_commit' => strtolower($expectedSourceCommit),
            'status' => 'PREPARED',
            'created_at' => $now,
            'updated_at' => $now,
            'dispatch' => null,
            'workflow' => null,
            'result' => null,
            'error' => null,
        ];

        jenxBuildStateWriteLocked($requestId, $record);

        return $record;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}


/** Fetch a record under lock. */
function jenxBuildStateGet(string $requestId): array
{
    $requestId = jenxBuildStateValidateRequestId($requestId);
    $lock = jenxBuildStateLock($requestId);

    try {
        return jenxBuildStateReadLocked($requestId);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}


/**
 * Move a request to its next state and optionally update approved fields.
 * Updates are deliberately limited to prevent callers changing identity,
 * branch, release tag, expected commit, or timestamps.
 */
function jenxBuildStateTransition(
    string $requestId,
    string $newStatus,
    array $updates = []
): array {
    $requestId = jenxBuildStateValidateRequestId($requestId);

    if (!in_array($newStatus, JENX_BUILD_STATES, true)) {
        throw new InvalidArgumentException('Unknown build state.');
    }

    $allowedUpdateFields = ['dispatch', 'workflow', 'result', 'error'];

    foreach (array_keys($updates) as $field) {
        if (!in_array($field, $allowedUpdateFields, true)) {
            throw new InvalidArgumentException(
                'Build state update contains a protected or unknown field.'
            );
        }
    }

    $lock = jenxBuildStateLock($requestId);

    try {
        $record = jenxBuildStateReadLocked($requestId);
        $currentStatus = $record['status'];

        $allowedNext = JENX_BUILD_STATE_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowedNext, true)) {
            throw new RuntimeException(
                'Invalid build state transition: '
                . $currentStatus . ' -> ' . $newStatus
            );
        }

        foreach ($updates as $field => $value) {
           $record[$field] = $value;
        }

        if ($newStatus === 'DISPATCHING') {
           $record['dispatch'] = [
              'attempt_started_at' => gmdate('c'),
              'outcome' => 'pending',
           ];
        }

        if ($newStatus === 'SUCCEEDED') {
           $result = $record['result'] ?? null;

            if (
                !is_array($result) ||
                ($result['status'] ?? null) !== 'success' ||
                ($result['request_id'] ?? null) !== $requestId ||
                ($result['release_tag'] ?? null) !== $record['release_tag'] ||
                strtolower((string) ($result['source_commit'] ?? ''))
                    !== $record['expected_source_commit'] ||
                !is_string($result['image'] ?? null) ||
                $result['image'] === '' ||
                !preg_match(
                '/^sha256:[0-9a-f]{64}$/',
                (string) ($result['digest'] ?? '')
                ) ||
                !ctype_digit((string) ($result['workflow_run_id'] ?? '')) ||
                (int) $result['workflow_run_id'] < 1
            ) {
                throw new RuntimeException(
                    'Cannot mark build successful: result record failed validation.'
                );
            }
        }
       
        $record['status'] = $newStatus;
        $record['updated_at'] = gmdate('c');

        // Ensure all values are JSON-serialisable before replacing the file.
        json_encode($record, JSON_THROW_ON_ERROR);

        jenxBuildStateWriteLocked($requestId, $record);

        return $record;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}



/**
 * Recover a dispatch that appears to have been abandoned.
 *
 * This does not contact GitHub and never retries a dispatch.
 * The grace period avoids interfering with a dispatch still in progress.
 */
function jenxBuildStateRecover(
    string $requestId,
    int $graceSeconds = 120
): array {
    if ($graceSeconds < 30 || $graceSeconds > 3600) {
        throw new InvalidArgumentException('Recovery grace period is invalid.');
    }

    $requestId = jenxBuildStateValidateRequestId($requestId);
    $lock = jenxBuildStateLock($requestId);

    try {
        $record = jenxBuildStateReadLocked($requestId);

        // All other states are left unchanged for Collect to reconcile.
        if ($record['status'] !== 'DISPATCHING') {
            return $record;
        }

        $updatedAt = strtotime((string) ($record['updated_at'] ?? ''));
        $now = time();

        // A malformed timestamp is ambiguous, so fail closed.
        $stale = $updatedAt === false
            || $updatedAt > $now
            || ($now - $updatedAt) >= $graceSeconds;

        if (!$stale) {
            return $record;
        }

        $record['status'] = 'RECONCILIATION_REQUIRED';
        $record['updated_at'] = gmdate('c');
        $record['error'] = [
            'code' => 'DISPATCH_OUTCOME_UNKNOWN',
            'message' => 'Dispatch outcome is unknown; reconcile with GitHub before proceeding.',
            'detected_at' => gmdate('c'),
        ];

        jenxBuildStateWriteLocked($requestId, $record);

        return $record;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
