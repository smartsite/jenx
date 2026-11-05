<?php

declare(strict_types=1);

/**
 * Jen-X Deployment Controller CLI — v1 skeleton.
 *
 * Read-only commands are implemented. Mutating commands validate eligibility,
 * then fail closed until the Docker/Compose adapter is implemented.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once __DIR__ . '/jenx-env-state.php';

const JENX_ENV_EXIT_OK = 0;
const JENX_ENV_EXIT_ARGUMENT = 2;
const JENX_ENV_EXIT_INELIGIBLE = 3;
const JENX_ENV_EXIT_FAILED = 4;
const JENX_ENV_EXIT_UNRESOLVED = 5;
const JENX_ENV_EXIT_LOCKED = 6;
const JENX_ENV_EXIT_DEPENDENCY = 7;

function jenxEnvCliUsage(): string
{
    return <<<TXT
Jen-X Deployment Controller v1 (skeleton)

Usage:
  php jenx-env.php status [dev|uat|prod] [--json]
  php jenx-env.php actions <env> [sha256:<digest>] [--json]
  php jenx-env.php operation <operation-uuid> [--json]
  php jenx-env.php deploy <env> <sha256:<digest>> [--confirm-prod] [--json]
  php jenx-env.php promote <source-env> <target-env> [--confirm-prod] [--json]
  php jenx-env.php rollback <env> [--confirm-prod] [--json]

Mutation commands are not executable until the deployment adapter is installed.
TXT;
}

function jenxEnvCliEmit(array $payload, bool $json, int $exitCode = 0): never
{
    if ($json) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    } else {
        if (isset($payload['error'])) {
            fwrite(STDERR, 'ERROR: ' . $payload['error']['message'] . "\n");
            if (isset($payload['error']['code'])) {
                fwrite(STDERR, 'Code: ' . $payload['error']['code'] . "\n");
            }
        } else {
            echo jenxEnvCliFormat($payload);
        }
    }
    exit($exitCode);
}

function jenxEnvCliFormat(array $payload): string
{
    if (isset($payload['environments']) && is_array($payload['environments'])) {
        $lines = [];
        foreach ($payload['environments'] as $environment) {
            $lines[] = jenxEnvCliFormatState($environment);
        }
        return implode("\n\n", $lines) . "\n";
    }
    if (isset($payload['environment']) && isset($payload['actions'])) {
        $lines = ['Environment: ' . $payload['environment'], 'Health: ' . ($payload['environment_health'] ?? 'unknown')];
        foreach ($payload['actions'] as $name => $action) {
            $lines[] = strtoupper($name) . ': ' . (($action['available'] ?? false) ? 'available' : 'unavailable — ' . ($action['reason'] ?? 'unspecified'));
        }
        return implode("\n", $lines) . "\n";
    }
    if (isset($payload['environment'])) {
        return jenxEnvCliFormatState($payload) . "\n";
    }
    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function jenxEnvCliFormatState(array $state): string
{
    $environment = (string) ($state['environment'] ?? 'unknown');
    if (($state['initialized'] ?? false) === false) {
        return strtoupper($environment) . ": NOT INITIALISED (no deployment state recorded)";
    }
    $observed = $state['observed'] ?? [];
    return implode("\n", [
        strtoupper($environment) . ': ' . ($state['environment_health'] ?? 'unknown'),
        '  desired digest: ' . (($state['desired']['digest'] ?? null) ?: 'none'),
        '  observed digest: ' . (($observed['digest'] ?? null) ?: 'none'),
        '  container: ' . ($observed['container_state'] ?? 'unknown'),
        '  application health: ' . ($observed['health'] ?? 'unknown'),
        '  active operation: ' . (($state['active_operation_id'] ?? null) ?: 'none'),
        '  observed at: ' . (($observed['observed_at'] ?? null) ?: 'never'),
    ]);
}

function jenxEnvCliError(string $code, string $message, bool $json, int $exitCode): never
{
    jenxEnvCliEmit(['schema_version' => 1, 'ok' => false, 'error' => ['code' => $code, 'message' => $message]], $json, $exitCode);
}

function jenxEnvCliReadStateView(string $environment): array
{
    $environment = jenxEnvValidateName($environment);
    $state = jenxEnvReadState($environment);
    if ($state === null) {
        return [
            'schema_version' => 1,
            'environment' => $environment,
            'initialized' => false,
            'environment_health' => 'not_deployed',
            'desired' => null,
            'observed' => ['image' => null, 'digest' => null, 'container_state' => 'unknown', 'health' => 'unknown', 'observed_at' => null],
            'current_deployment_id' => null,
            'previous_healthy_deployment_id' => null,
            'active_operation_id' => null,
            'last_successful_operation_id' => null,
            'deployments' => [],
            'updated_at' => null,
        ];
    }
    return $state + ['initialized' => true, 'environment_health' => jenxEnvIsHealthy($state) ? 'healthy' : ((($state['observed']['health'] ?? 'unknown') === 'unhealthy') ? 'unhealthy' : 'unknown')];
}

$args = $argv;
array_shift($args);
$json = false;
$confirmProd = false;
$positionals = [];
foreach ($args as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--confirm-prod') {
        $confirmProd = true;
    } elseif (str_starts_with($arg, '--')) {
        jenxEnvCliError('INVALID_ARGUMENT', 'Unknown option: ' . $arg, $json, JENX_ENV_EXIT_ARGUMENT);
    } else {
        $positionals[] = $arg;
    }
}

$command = $positionals[0] ?? 'help';
$operationalCommands = ['status', 'actions', 'operation', 'deploy', 'promote', 'rollback'];
if (!in_array($command, $operationalCommands, true)) {
    jenxEnvCliEmit(['schema_version' => 1, 'usage' => jenxEnvCliUsage()], $json, $command === 'help' ? 0 : JENX_ENV_EXIT_ARGUMENT);
}

try {
    switch ($command) {
        case 'status':
            if (count($positionals) > 2) {
                throw new InvalidArgumentException('status accepts at most one environment.');
            }
            if (isset($positionals[1])) {
                $state = jenxEnvCliReadStateView($positionals[1]);
                jenxEnvCliEmit($state, $json);
            }
            $environments = [];
            foreach (JENX_ENVIRONMENTS as $environment) {
                $environments[] = jenxEnvCliReadStateView($environment);
            }
            jenxEnvCliEmit(['schema_version' => 1, 'environments' => $environments], $json);

        case 'actions':
            if (count($positionals) < 2 || count($positionals) > 3) {
                throw new InvalidArgumentException('actions requires an environment and optionally a candidate digest.');
            }
            $eligibility = jenxEnvEligibility($positionals[1], $positionals[2] ?? null);
            jenxEnvCliEmit($eligibility, $json);

        case 'operation':
            if (count($positionals) !== 2) {
                throw new InvalidArgumentException('operation requires an operation UUID.');
            }
            $operationId = jenxEnvValidateOperationId($positionals[1]);
            $operation = jenxEnvReadOperation($operationId);
            if ($operation === null) {
                jenxEnvCliError('OPERATION_NOT_FOUND', 'No operation record exists for that ID.', $json, JENX_ENV_EXIT_ARGUMENT);
            }
            jenxEnvCliEmit(['schema_version' => 1, 'ok' => true, 'operation' => $operation], $json);

        case 'deploy':
            if (count($positionals) !== 3) {
                throw new InvalidArgumentException('deploy requires an environment and image digest.');
            }
            $environment = jenxEnvValidateName($positionals[1]);
            if ($environment === 'prod' && !$confirmProd) {
                jenxEnvCliError('PROD_CONFIRMATION_REQUIRED', 'Mutating PROD requires --confirm-prod.', $json, JENX_ENV_EXIT_ARGUMENT);
            }
            $eligibility = jenxEnvEligibility($environment, $positionals[2]);
            if (!$eligibility['actions']['deploy']['available']) {
                jenxEnvCliError('ACTION_NOT_ELIGIBLE', $eligibility['actions']['deploy']['reason'] ?? 'Deploy is unavailable.', $json, JENX_ENV_EXIT_INELIGIBLE);
            }
            jenxEnvCliError('DEPLOYMENT_ADAPTER_UNAVAILABLE', 'Preflight passed, but no Docker/Compose deployment adapter is installed. No state was changed.', $json, JENX_ENV_EXIT_DEPENDENCY);

        case 'promote':
            if (count($positionals) !== 3) {
                throw new InvalidArgumentException('promote requires source and target environments.');
            }
            $source = jenxEnvValidateName($positionals[1]);
            $target = jenxEnvValidateName($positionals[2]);
            if ($target === 'prod' && !$confirmProd) {
                jenxEnvCliError('PROD_CONFIRMATION_REQUIRED', 'Promotion to PROD requires --confirm-prod.', $json, JENX_ENV_EXIT_ARGUMENT);
            }
            $eligibility = jenxEnvEligibility($target, null, $source);
            if (!$eligibility['actions']['promote']['available']) {
                jenxEnvCliError('ACTION_NOT_ELIGIBLE', $eligibility['actions']['promote']['reason'] ?? 'Promotion is unavailable.', $json, JENX_ENV_EXIT_INELIGIBLE);
            }
            jenxEnvCliError('DEPLOYMENT_ADAPTER_UNAVAILABLE', 'Promotion preflight passed, but no Docker/Compose deployment adapter is installed. No state was changed.', $json, JENX_ENV_EXIT_DEPENDENCY);

        case 'rollback':
            if (count($positionals) !== 2) {
                throw new InvalidArgumentException('rollback requires an environment.');
            }
            $environment = jenxEnvValidateName($positionals[1]);
            if ($environment === 'prod' && !$confirmProd) {
                jenxEnvCliError('PROD_CONFIRMATION_REQUIRED', 'Rollback of PROD requires --confirm-prod.', $json, JENX_ENV_EXIT_ARGUMENT);
            }
            $eligibility = jenxEnvEligibility($environment);
            if (!$eligibility['actions']['rollback']['available']) {
                jenxEnvCliError('ACTION_NOT_ELIGIBLE', $eligibility['actions']['rollback']['reason'] ?? 'Rollback is unavailable.', $json, JENX_ENV_EXIT_INELIGIBLE);
            }
            jenxEnvCliError('DEPLOYMENT_ADAPTER_UNAVAILABLE', 'Rollback preflight passed, but no Docker/Compose deployment adapter is installed. No state was changed.', $json, JENX_ENV_EXIT_DEPENDENCY);
    }
} catch (InvalidArgumentException $exception) {
    jenxEnvCliError('INVALID_ARGUMENT', $exception->getMessage(), $json, JENX_ENV_EXIT_ARGUMENT);
} catch (Throwable $exception) {
    error_log('Jen-X environment CLI: ' . get_class($exception) . ': ' . $exception->getMessage());
    jenxEnvCliError('STATE_UNAVAILABLE', 'Environment state is unavailable or failed validation.', $json, JENX_ENV_EXIT_DEPENDENCY);
}
