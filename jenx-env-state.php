<?php

declare(strict_types=1);

/**
 * Jen-X environment state and eligibility helpers (v1 skeleton).
 * No Docker, Compose, network, or deployment side effects are performed here.
 */

const JENX_ENV_SCHEMA_VERSION = 1;
const JENX_ENVIRONMENTS = ['dev', 'uat', 'prod'];
const JENX_ENV_ACTIVE_OPERATION_STATES = ['PREPARED', 'APPLYING', 'VERIFYING', 'RECONCILIATION_REQUIRED'];
const JENX_ENV_TERMINAL_OPERATION_STATES = ['SUCCEEDED', 'FAILED', 'CANCELLED'];

function jenxEnvStateDirectory(): string
{
    $override = getenv('JENX_ENV_STATE_DIRECTORY');
    return is_string($override) && $override !== ''
        ? rtrim($override, '/')
        : '/var/www/jenx/state/environments';
}

function jenxEnvBuildStateDirectory(): string
{
    $override = getenv('JENX_BUILD_STATE_DIRECTORY');
    return is_string($override) && $override !== ''
        ? rtrim($override, '/')
        : '/var/www/jenx/state/builds';
}

function jenxEnvValidateName(string $environment): string
{
    $environment = strtolower($environment);
    if (!in_array($environment, JENX_ENVIRONMENTS, true)) {
        throw new InvalidArgumentException('Environment must be dev, uat, or prod.');
    }
    return $environment;
}

function jenxEnvValidateDigest(string $digest): string
{
    if (!preg_match('/^sha256:[a-f0-9]{64}$/i', $digest)) {
        throw new InvalidArgumentException('Image digest must be a full sha256:<64 hexadecimal characters> value.');
    }
    return 'sha256:' . strtolower(substr($digest, 7));
}

function jenxEnvValidateOperationId(string $operationId): string
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $operationId)) {
        throw new InvalidArgumentException('Operation ID must be a UUID.');
    }
    return strtolower($operationId);
}

function jenxEnvUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
}

function jenxEnvTimestamp(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function jenxEnvDefaultState(string $environment): array
{
    return [
        'schema_version' => JENX_ENV_SCHEMA_VERSION,
        'environment' => jenxEnvValidateName($environment),
        'desired' => null,
        'observed' => [
            'image' => null,
            'digest' => null,
            'container_state' => 'unknown',
            'health' => 'unknown',
            'observed_at' => null,
        ],
        'current_deployment_id' => null,
        'previous_healthy_deployment_id' => null,
        'active_operation_id' => null,
        'last_successful_operation_id' => null,
        // Newest first. Each item is a verified deployment record.
        'deployments' => [],
        'updated_at' => null,
    ];
}

function jenxEnvEnsureDirectory(string $directory, bool $create = false): void
{
    if (is_link($directory)) {
        throw new RuntimeException('State directory must not be a symlink.');
    }
    if (!is_dir($directory)) {
        if (!$create) {
            throw new RuntimeException('Environment state directory does not exist.');
        }
        if (!@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create environment state directory.');
        }
        @chmod($directory, 0750);
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Environment state directory is not writable.');
    }
}

function jenxEnvEnsureLayout(bool $create = false): void
{
    $base = jenxEnvStateDirectory();
    jenxEnvEnsureDirectory($base, $create);
    foreach (['operations', 'locks'] as $child) {
        $path = $base . '/' . $child;
        if (!is_dir($path) && !$create) {
            continue;
        }
        jenxEnvEnsureDirectory($path, $create);
    }
}

function jenxEnvStatePath(string $environment): string
{
    return jenxEnvStateDirectory() . '/' . jenxEnvValidateName($environment) . '.json';
}

function jenxEnvOperationPath(string $operationId): string
{
    return jenxEnvStateDirectory() . '/operations/' . jenxEnvValidateOperationId($operationId) . '.json';
}

function jenxEnvReadJsonFile(string $path): array
{
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('State file is missing or unsafe.');
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Could not read state file.');
    }
    try {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('State file contains invalid JSON.', 0, $exception);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('State file must contain a JSON object.');
    }
    return $decoded;
}

function jenxEnvReadState(string $environment): ?array
{
    $environment = jenxEnvValidateName($environment);
    $path = jenxEnvStatePath($environment);
    if (!file_exists($path) && !is_link($path)) {
        return null;
    }
    $state = jenxEnvReadJsonFile($path);
    jenxEnvValidateState($state, $environment);
    return $state;
}

function jenxEnvValidateState(array $state, string $expectedEnvironment): void
{
    if (($state['schema_version'] ?? null) !== JENX_ENV_SCHEMA_VERSION) {
        throw new RuntimeException('Unsupported environment state schema version.');
    }
    if (($state['environment'] ?? null) !== $expectedEnvironment) {
        throw new RuntimeException('Environment state identity does not match its filename.');
    }
    if (!is_array($state['observed'] ?? null) || !is_array($state['deployments'] ?? null)) {
        throw new RuntimeException('Environment state is missing required structures.');
    }
    foreach ($state['deployments'] as $deployment) {
        if (!is_array($deployment)
            || !is_string($deployment['deployment_id'] ?? null)
            || !is_string($deployment['digest'] ?? null)
            || !preg_match('/^sha256:[a-f0-9]{64}$/', $deployment['digest'])
            || !is_bool($deployment['verified_healthy'] ?? null)
        ) {
            throw new RuntimeException('Environment deployment history failed validation.');
        }
    }
}

function jenxEnvWriteJsonAtomic(string $path, array $data): void
{
    $directory = dirname($path);
    jenxEnvEnsureDirectory($directory, true);
    if (is_link($path)) {
        throw new RuntimeException('Refusing to replace a symlink state file.');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = tempnam($directory, '.jenx-tmp-');
    if ($temporary === false) {
        throw new RuntimeException('Could not create temporary state file.');
    }
    try {
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            throw new RuntimeException('Could not write complete state file.');
        }
        @chmod($temporary, 0640);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Could not atomically replace state file.');
        }
        @chmod($path, 0640);
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function jenxEnvWriteState(string $environment, array $state): void
{
    $environment = jenxEnvValidateName($environment);
    jenxEnvValidateState($state, $environment);
    $state['updated_at'] = jenxEnvTimestamp();
    jenxEnvWriteJsonAtomic(jenxEnvStatePath($environment), $state);
}

/** Acquire a per-environment lock; caller must fclose the returned handle. */
function jenxEnvAcquireLock(string $environment, bool $nonBlocking = true)
{
    $environment = jenxEnvValidateName($environment);
    jenxEnvEnsureLayout(true);
    $path = jenxEnvStateDirectory() . '/locks/' . $environment . '.lock';
    if (is_link($path)) {
        throw new RuntimeException('Environment lock must not be a symlink.');
    }
    $handle = fopen($path, 'c');
    if ($handle === false) {
        throw new RuntimeException('Could not open environment lock.');
    }
    $mode = LOCK_EX | ($nonBlocking ? LOCK_NB : 0);
    if (!flock($handle, $mode)) {
        fclose($handle);
        return false;
    }
    return $handle;
}

function jenxEnvReadOperation(string $operationId): ?array
{
    $operationId = jenxEnvValidateOperationId($operationId);
    $path = jenxEnvOperationPath($operationId);
    if (!file_exists($path) && !is_link($path)) {
        return null;
    }
    $operation = jenxEnvReadJsonFile($path);
    if (($operation['schema_version'] ?? null) !== JENX_ENV_SCHEMA_VERSION
        || ($operation['operation_id'] ?? null) !== $operationId
        || !in_array($operation['status'] ?? null, array_merge(JENX_ENV_ACTIVE_OPERATION_STATES, JENX_ENV_TERMINAL_OPERATION_STATES), true)
    ) {
        throw new RuntimeException('Operation record failed validation.');
    }
    return $operation;
}

function jenxEnvWriteOperation(array $operation): void
{
    $operationId = jenxEnvValidateOperationId((string) ($operation['operation_id'] ?? ''));
    if (($operation['schema_version'] ?? null) !== JENX_ENV_SCHEMA_VERSION) {
        throw new InvalidArgumentException('Unsupported operation schema version.');
    }
    if (!in_array($operation['status'] ?? null, array_merge(JENX_ENV_ACTIVE_OPERATION_STATES, JENX_ENV_TERMINAL_OPERATION_STATES), true)) {
        throw new InvalidArgumentException('Invalid operation status.');
    }
    jenxEnvWriteJsonAtomic(jenxEnvOperationPath($operationId), $operation);
}

/** Locate a build record only when its success result is fully validated. */
function jenxEnvFindValidatedBuild(string $digest): ?array
{
    $digest = jenxEnvValidateDigest($digest);
    $directory = jenxEnvBuildStateDirectory();
    if (is_link($directory) || !is_dir($directory)) {
        return null;
    }
    foreach (glob($directory . '/*.json') ?: [] as $path) {
        if (is_link($path) || !is_file($path)) {
            continue;
        }
        try {
            $record = jenxEnvReadJsonFile($path);
        } catch (Throwable) {
            continue;
        }
        $result = $record['result'] ?? null;
        $recordId = $record['request_id'] ?? null;
        $expectedCommit = strtolower((string) ($record['expected_source_commit'] ?? ''));
        $resultCommit = strtolower((string) ($result['source_commit'] ?? ''));
        if (($record['status'] ?? null) !== 'SUCCEEDED'
            || !is_string($recordId)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $recordId)
            || !is_array($result)
            || ($result['status'] ?? null) !== 'success'
            || ($result['request_id'] ?? null) !== $recordId
            || !is_string($record['release_tag'] ?? null)
            || ($result['release_tag'] ?? null) !== $record['release_tag']
            || !preg_match('/^[0-9a-f]{40}$/', $expectedCommit)
            || !preg_match('/^[0-9a-f]{40}$/', $resultCommit)
            || !hash_equals($expectedCommit, $resultCommit)
            || !is_string($result['digest'] ?? null)
            || !preg_match('/^sha256:[0-9a-f]{64}$/i', $result['digest'])
            || !hash_equals($digest, strtolower($result['digest']))
            || !is_string($result['image'] ?? null)
            || $result['image'] === ''
            || !ctype_digit((string) ($result['workflow_run_id'] ?? ''))
            || (int) $result['workflow_run_id'] < 1
            || !is_bool($record['test_only'] ?? null)
            || !is_bool($result['test_only'] ?? null)
            || $result['test_only'] !== $record['test_only']
            || $result['test_only'] !== false
        ) {
            continue;
        }
        return [
            'request_id' => $record['request_id'] ?? null,
            'release_tag' => $result['release_tag'],
            'source_commit' => strtolower($result['source_commit']),
            'image' => $result['image'],
            'digest' => $digest,
            'workflow_run_id' => (int) $result['workflow_run_id'],
            'test_only' => false,
        ];
    }
    return null;
}

function jenxEnvFindDeployment(array $state, ?string $deploymentId): ?array
{
    if ($deploymentId === null || $deploymentId === '') {
        return null;
    }
    foreach ($state['deployments'] as $deployment) {
        if (($deployment['deployment_id'] ?? null) === $deploymentId) {
            return $deployment;
        }
    }
    return null;
}

function jenxEnvIsHealthy(array $state): bool
{
    $observed = $state['observed'] ?? [];
    $current = jenxEnvFindDeployment($state, $state['current_deployment_id'] ?? null);
    return is_array($current)
        && ($current['verified_healthy'] ?? false) === true
        && ($observed['container_state'] ?? null) === 'running'
        && ($observed['health'] ?? null) === 'healthy'
        && is_string($observed['digest'] ?? null)
        && is_string($current['digest'] ?? null)
        && hash_equals($current['digest'], $observed['digest']);
}

function jenxEnvIsBusy(?array $state): bool
{
    if ($state === null) {
        return false;
    }
    if (($state['active_operation_id'] ?? null) !== null) {
        return true;
    }
    $operationId = $state['active_operation_id'] ?? null;
    if (is_string($operationId)) {
        $operation = jenxEnvReadOperation($operationId);
        return $operation !== null && in_array($operation['status'], JENX_ENV_ACTIVE_OPERATION_STATES, true);
    }
    return false;
}

function jenxEnvAction(bool $available, ?string $reason = null): array
{
    return ['available' => $available, 'reason' => $available ? null : $reason];
}

/** Shared CLI/API eligibility rules. A supplied candidate digest is validated against build state. */
function jenxEnvEligibility(string $environment, ?string $candidateDigest = null, ?string $sourceEnvironment = null): array
{
    $environment = jenxEnvValidateName($environment);
    $state = jenxEnvReadState($environment);
    $busy = jenxEnvIsBusy($state);
    $candidate = null;
    $candidateError = null;
    if ($candidateDigest !== null) {
        try {
            $candidateDigest = jenxEnvValidateDigest($candidateDigest);
            $candidate = jenxEnvFindValidatedBuild($candidateDigest);
            if ($candidate === null) {
                $candidateError = 'No validated, non-test-only successful build matches this digest.';
            }
        } catch (InvalidArgumentException $exception) {
            $candidateError = $exception->getMessage();
        }
    }

    $deployReason = null;
    if ($busy) {
        $deployReason = 'An operation is active or requires reconciliation for this environment.';
    } elseif ($candidateDigest === null) {
        $deployReason = 'Supply a candidate digest to evaluate deploy eligibility.';
    } elseif ($candidateError !== null) {
        $deployReason = $candidateError;
    }

    $sourceReason = null;
    $sourceState = null;
    if ($sourceEnvironment === null) {
        $sourceReason = 'A source environment is required to evaluate promotion.';
    } else {
        $sourceEnvironment = jenxEnvValidateName($sourceEnvironment);
        $sourceState = jenxEnvReadState($sourceEnvironment);
        if ($sourceEnvironment === $environment) {
            $sourceReason = 'Source and target environments must differ.';
        } elseif ($sourceState === null || !jenxEnvIsHealthy($sourceState)) {
            $sourceReason = 'Source environment does not have a verified healthy running deployment.';
        } elseif (jenxEnvIsBusy($sourceState)) {
            $sourceReason = 'Source environment has an active or unresolved operation.';
        } elseif ($busy) {
            $sourceReason = 'Target environment has an active or unresolved operation.';
        }
    }

    $rollbackCandidate = null;
    if ($state !== null) {
        foreach ($state['deployments'] as $deployment) {
            if (($deployment['deployment_id'] ?? null) !== ($state['current_deployment_id'] ?? null)
                && ($deployment['verified_healthy'] ?? false) === true
            ) {
                $rollbackCandidate = $deployment;
                break;
            }
        }
    }
    $rollbackReason = null;
    if ($busy) {
        $rollbackReason = 'An operation is active or requires reconciliation for this environment.';
    } elseif ($rollbackCandidate === null) {
        $rollbackReason = 'No previous verified healthy deployment is recorded.';
    }

    return [
        'schema_version' => JENX_ENV_SCHEMA_VERSION,
        'environment' => $environment,
        'initialized' => $state !== null,
        'environment_health' => $state === null ? 'not_deployed' : ((jenxEnvIsHealthy($state)) ? 'healthy' : (($state['observed']['health'] ?? 'unknown') === 'unhealthy' ? 'unhealthy' : 'unknown')),
        'actions' => [
            'deploy' => jenxEnvAction($deployReason === null, $deployReason),
            'promote' => jenxEnvAction($sourceReason === null, $sourceReason),
            'rollback' => jenxEnvAction($rollbackReason === null, $rollbackReason),
        ],
        'candidate' => $candidate,
        'rollback_candidate' => $rollbackCandidate,
        'active_operation_id' => $state['active_operation_id'] ?? null,
    ];
}

/** Create a durable operation intent before any deployment side effect. */
function jenxEnvCreateOperation(
    string $type,
    string $targetEnvironment,
    ?string $sourceEnvironment = null,
    ?string $requestedDigest = null,
    ?string $previousDigest = null
): array {
    if (!in_array($type, ['deploy', 'promote', 'rollback'], true)) {
        throw new InvalidArgumentException('Invalid operation type.');
    }
    $targetEnvironment = jenxEnvValidateName($targetEnvironment);
    if ($sourceEnvironment !== null) {
        $sourceEnvironment = jenxEnvValidateName($sourceEnvironment);
    }
    if ($requestedDigest !== null) {
        $requestedDigest = jenxEnvValidateDigest($requestedDigest);
    }
    if ($previousDigest !== null) {
        $previousDigest = jenxEnvValidateDigest($previousDigest);
    }
    if ($type === 'promote' && ($sourceEnvironment === null || $sourceEnvironment === $targetEnvironment)) {
        throw new InvalidArgumentException('Promotion requires distinct source and target environments.');
    }
    if ($type === 'deploy' && $requestedDigest === null) {
        throw new InvalidArgumentException('Deploy operation requires a requested digest.');
    }

    $now = jenxEnvTimestamp();
    $operation = [
        'schema_version' => JENX_ENV_SCHEMA_VERSION,
        'operation_id' => jenxEnvUuid(),
        'type' => $type,
        'status' => 'PREPARED',
        'source_environment' => $sourceEnvironment,
        'target_environment' => $targetEnvironment,
        'requested_digest' => $requestedDigest,
        'previous_digest' => $previousDigest,
        'started_at' => $now,
        'updated_at' => $now,
        'finished_at' => null,
        'verification' => ['image_digest_matches' => null, 'application_healthy' => null],
        'error_code' => null,
        'error_message' => null,
    ];
    $path = jenxEnvOperationPath($operation['operation_id']);
    if (file_exists($path) || is_link($path)) {
        throw new RuntimeException('Generated operation ID already exists.');
    }
    jenxEnvWriteOperation($operation);
    return $operation;
}

/** Apply a legal operation-state transition; terminal states cannot transition. */
function jenxEnvTransitionOperation(array $operation, string $newStatus, array $updates = []): array
{
    $transitions = [
        'PREPARED' => ['APPLYING', 'FAILED', 'CANCELLED', 'RECONCILIATION_REQUIRED'],
        'APPLYING' => ['VERIFYING', 'FAILED', 'RECONCILIATION_REQUIRED'],
        'VERIFYING' => ['SUCCEEDED', 'FAILED', 'RECONCILIATION_REQUIRED'],
        'RECONCILIATION_REQUIRED' => ['APPLYING', 'VERIFYING', 'SUCCEEDED', 'FAILED'],
        'SUCCEEDED' => [],
        'FAILED' => [],
        'CANCELLED' => [],
    ];
    $current = $operation['status'] ?? null;
    if (!is_string($current) || !array_key_exists($current, $transitions)) {
        throw new InvalidArgumentException('Current operation status is invalid.');
    }
    if (!in_array($newStatus, $transitions[$current], true)) {
        throw new DomainException("Illegal operation transition: {$current} -> {$newStatus}.");
    }
    $allowedUpdates = ['verification', 'error_code', 'error_message', 'requested_digest', 'previous_digest'];
    foreach ($updates as $key => $value) {
        if (!in_array($key, $allowedUpdates, true)) {
            throw new InvalidArgumentException('Operation update field is not permitted: ' . $key);
        }
        if ($key === 'verification') {
            if (!is_array($value)
                || !array_key_exists('image_digest_matches', $value)
                || !array_key_exists('application_healthy', $value)
            ) {
                throw new InvalidArgumentException('Verification update must include both verification results.');
            }
            foreach (['image_digest_matches', 'application_healthy'] as $check) {
                if (!is_bool($value[$check]) && $value[$check] !== null) {
                    throw new InvalidArgumentException('Verification results must be booleans or null.');
                }
            }
        }
        if (in_array($key, ['requested_digest', 'previous_digest'], true) && $value !== null) {
            $value = jenxEnvValidateDigest((string) $value);
        }
        $operation[$key] = $value;
    }
    if ($newStatus === 'SUCCEEDED') {
        $verification = $operation['verification'] ?? [];
        if (($verification['image_digest_matches'] ?? false) !== true
            || ($verification['application_healthy'] ?? false) !== true
        ) {
            throw new DomainException('Cannot mark operation successful until both verification checks pass.');
        }
    }
    if ($newStatus === 'FAILED' && (!is_string($operation['error_code'] ?? null) || $operation['error_code'] === '')) {
        throw new DomainException('A failed operation must include an error_code.');
    }
    $operation['status'] = $newStatus;
    $operation['updated_at'] = jenxEnvTimestamp();
    if (in_array($newStatus, JENX_ENV_TERMINAL_OPERATION_STATES, true)) {
        $operation['finished_at'] = $operation['updated_at'];
    }
    jenxEnvWriteOperation($operation);
    return $operation;
}
