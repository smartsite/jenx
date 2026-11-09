<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../jenx-env-state.php';

function deploymentStatusReadStateView(string $environment): array
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
            'deployments' => [],
            'updated_at' => null,
        ];
    }

    return $state + [
        'initialized' => true,
        'environment_health' => jenxEnvIsHealthy($state)
            ? 'healthy'
            : ((($state['observed']['health'] ?? 'unknown') === 'unhealthy')
                ? 'unhealthy'
                : 'unknown'),
    ];
}

try {
    $environments = [];

    foreach (JENX_ENVIRONMENTS as $environment) {
        $environments[] = deploymentStatusReadStateView($environment);
    }

    echo json_encode([
        'schema_version' => 1,
        'ok' => true,
        'environments' => $environments,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'schema_version' => 1,
        'ok' => false,
        'error' => [
            'code' => 'DEPLOYMENT_STATUS_FAILED',
            'message' => $e->getMessage(),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}