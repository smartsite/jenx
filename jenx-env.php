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
const JENX_DEPLOY_ADAPTER = __DIR__ . '/jenx-deploy.php';

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

    $deployments = $state['deployments'] ?? [];

    foreach ($deployments as &$deployment) {
        if (
            isset($deployment['release_tag'], $deployment['source_commit'], $deployment['image'])
        ) {
            continue;
        }

        $digest = $deployment['digest'] ?? null;

        if (!is_string($digest)) {
            continue;
        }

        $build = jenxEnvFindValidatedBuild($digest);

        if ($build === null) {
            continue;
        }

        $deployment['image'] = $build['image'];
        $deployment['source_commit'] = $build['source_commit'];
        $deployment['release_tag'] = $build['release_tag'];
    }

    unset($deployment);

    return $state + [
        'initialized' => true,
        'environment_health' => jenxEnvIsHealthy($state)
            ? 'healthy'
            : ((($state['observed']['health'] ?? 'unknown') === 'unhealthy')
                ? 'unhealthy'
                : 'unknown'),
        'deployments' => $deployments,
    ];
}

function jenxEnvRecordSuccessfulDeployment(
    string $environment,
    array $operation,
    array $adapterResult
): array {
    $state = jenxEnvReadState($environment);

    if ($state === null) {
        $state = jenxEnvDefaultState($environment);
    }

    $previousCurrentDeploymentId = $state['current_deployment_id'] ?? null;

    $observed = $adapterResult['observed'] ?? [];
    $verification = $adapterResult['verification'] ?? [];

     $digest = jenxEnvValidateDigest(
        (string) $operation['requested_digest']
    );

    $build = jenxEnvFindValidatedBuild($digest);

    $deployment = [
        'deployment_id' => $operation['operation_id'],
        'operation_id' => $operation['operation_id'],
        'digest' => $digest,
        'image' => $build['image'] ?? null,
        'source_commit' => $build['source_commit'] ?? null,
        'release_tag' => $build['release_tag'] ?? null,
        'verified_healthy' =>
            ($verification['image_digest_matches'] ?? false) === true
            && ($verification['application_healthy'] ?? false) === true,
        'deployed_at' => jenxEnvTimestamp(),
    ];

    array_unshift($state['deployments'], $deployment);

    $state['desired'] = [
        'digest' => $deployment['digest'],
    ];

    $state['observed'] = [
        'image' => $observed['image'] ?? null,
        'digest' => $observed['digest'] ?? null,
        'container_state' => $observed['container_state'] ?? 'unknown',
        'health' =>
            ($verification['application_healthy'] ?? false) === true
                ? 'healthy'
                : 'unknown',
        'observed_at' => jenxEnvTimestamp(),
    ];

    $state['previous_healthy_deployment_id'] =
        $previousCurrentDeploymentId;

    $state['current_deployment_id'] =
        $operation['operation_id'];

    $state['active_operation_id'] = null;
    $state['last_successful_operation_id'] =
        $operation['operation_id'];

    jenxEnvWriteState($environment, $state);

    return $state;
}

function jenxEnvRunDeploymentAdapter(
    string $environment,
    string $digest
): array {
    if (!is_file(JENX_DEPLOY_ADAPTER) || !is_readable(JENX_DEPLOY_ADAPTER)) {
        throw new RuntimeException('Deployment adapter is missing or unreadable.');
    }

    $command = [
        PHP_BINARY,
        JENX_DEPLOY_ADAPTER,
        'deploy',
        $environment,
        $digest,
        '--json',
    ];

    $escaped = array_map(
        static fn(string $arg): string => escapeshellarg($arg),
        $command
    );

    $process = proc_open(
        implode(' ', $escaped),
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start deployment adapter.');
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($stdout === false || trim($stdout) === '') {
        throw new RuntimeException(
            'Deployment adapter returned no JSON output: ' .
            trim((string) $stderr)
        );
    }

    try {
        $result = json_decode(
            $stdout,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        throw new RuntimeException(
            'Deployment adapter returned invalid JSON: ' .
            $exception->getMessage()
        );
    }

    if (!is_array($result)) {
        throw new RuntimeException('Deployment adapter returned an invalid result.');
    }

    $result['_exit_code'] = $exitCode;

    return $result;
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
            $requestedDigest = jenxEnvValidateDigest($positionals[2]);

            if ($environment === 'prod' && !$confirmProd) {
                jenxEnvCliError(
                    'PROD_CONFIRMATION_REQUIRED',
                    'Mutating PROD requires --confirm-prod.',
                    $json,
                    JENX_ENV_EXIT_ARGUMENT
                );
            }

            $eligibility = jenxEnvEligibility($environment, $requestedDigest);

            if (!$eligibility['actions']['deploy']['available']) {
                jenxEnvCliError(
                    'ACTION_NOT_ELIGIBLE',
                    $eligibility['actions']['deploy']['reason'] ?? 'Deploy is unavailable.',
                    $json,
                    JENX_ENV_EXIT_INELIGIBLE
                );
            }

            $state = jenxEnvReadState($environment);
            $previousDigest = $state['observed']['digest'] ?? null;

            $operation = jenxEnvCreateOperation(
                'deploy',
                $environment,
                null,
                $requestedDigest,
                $previousDigest
            );

            $operation = jenxEnvTransitionOperation(
                $operation,
                'APPLYING'
            );

            try {
                $adapterResult = jenxEnvRunDeploymentAdapter(
                    $environment,
                    $requestedDigest
                );

                $verification = $adapterResult['verification'] ?? [
                    'image_digest_matches' => false,
                    'application_healthy' => false,
                ];

                if (($adapterResult['ok'] ?? false) !== true) {
                    $operation = jenxEnvTransitionOperation(
                        $operation,
                        'VERIFYING',
                        [
                            'verification' => [
                                'image_digest_matches' => (bool) ($verification['image_digest_matches'] ?? false),
                                'application_healthy' => (bool) ($verification['application_healthy'] ?? false),
                            ],
                        ]
                    );

                    $operation = jenxEnvTransitionOperation(
                        $operation,
                        'FAILED',
                        [
                            'error_code' => (string) ($adapterResult['error_code'] ?? 'DEPLOYMENT_FAILED'),
                            'error_message' => (string) ($adapterResult['error_message'] ?? 'Deployment adapter reported failure.'),
                        ]
                    );

                    jenxEnvCliEmit(
                        [
                            'schema_version' => 1,
                            'ok' => false,
                            'operation' => $operation,
                            'adapter' => $adapterResult,
                        ],
                        $json,
                        JENX_ENV_EXIT_FAILED
                    );
                }

                $operation = jenxEnvTransitionOperation(
                    $operation,
                    'VERIFYING',
                    [
                        'verification' => [
                            'image_digest_matches' => (bool) ($verification['image_digest_matches'] ?? false),
                            'application_healthy' => (bool) ($verification['application_healthy'] ?? false),
                        ],
                    ]
                );

                $operation = jenxEnvTransitionOperation(
                    $operation,
                    'SUCCEEDED'
                );

                jenxEnvRecordSuccessfulDeployment(
                    $environment,
                    $operation,
                    $adapterResult
                );

                jenxEnvCliEmit(
                    [
                        'schema_version' => 1,
                        'ok' => true,
                        'operation' => $operation,
                        'adapter' => $adapterResult,
                    ],
                    $json,
                    JENX_ENV_EXIT_OK
                );

            } catch (Throwable $exception) {
                try {
                    $operation = jenxEnvTransitionOperation(
                        $operation,
                        'RECONCILIATION_REQUIRED',
                        [
                            'error_code' => 'DEPLOYMENT_OUTCOME_UNRESOLVED',
                            'error_message' => $exception->getMessage(),
                        ]
                    );
                } catch (Throwable $transitionException) {
                    error_log(
                        'Jen-X deployment operation could not be marked unresolved: ' .
                        $transitionException->getMessage()
                    );
                }

                jenxEnvCliEmit(
                    [
                        'schema_version' => 1,
                        'ok' => false,
                        'operation' => $operation,
                        'error' => [
                            'code' => 'DEPLOYMENT_OUTCOME_UNRESOLVED',
                            'message' => $exception->getMessage(),
                        ],
                    ],
                    $json,
                    JENX_ENV_EXIT_UNRESOLVED
                );
            }
               case 'promote':
                    if (count($positionals) !== 3) {
                        throw new InvalidArgumentException('promote requires source and target environments.');
                    }

                    $source = jenxEnvValidateName($positionals[1]);
                    $target = jenxEnvValidateName($positionals[2]);

                    if ($target === 'prod' && !$confirmProd) {
                        jenxEnvCliError(
                            'PROD_CONFIRMATION_REQUIRED',
                            'Promotion to PROD requires --confirm-prod.',
                            $json,
                            JENX_ENV_EXIT_ARGUMENT
                        );
                    }

                    $eligibility = jenxEnvEligibility($target, null, $source);

                    if (!$eligibility['actions']['promote']['available']) {
                        jenxEnvCliError(
                            'ACTION_NOT_ELIGIBLE',
                            $eligibility['actions']['promote']['reason'] ?? 'Promotion is unavailable.',
                            $json,
                            JENX_ENV_EXIT_INELIGIBLE
                        );
                    }

                    /*
                    * Promotion uses the exact digest of the source environment's
                    * current verified healthy deployment. No rebuild or new image
                    * lookup is performed.
                    */
                    $sourceState = jenxEnvReadState($source);
                    if ($sourceState === null) {
                        jenxEnvCliError(
                            'SOURCE_STATE_UNAVAILABLE',
                            'Source environment state is unavailable.',
                            $json,
                            JENX_ENV_EXIT_DEPENDENCY
                        );
                    }

                    $sourceDeployment = jenxEnvFindDeployment(
                        $sourceState,
                        $sourceState['current_deployment_id'] ?? null
                    );

                    if ($sourceDeployment === null
                        || ($sourceDeployment['verified_healthy'] ?? false) !== true
                    ) {
                        jenxEnvCliError(
                            'SOURCE_DEPLOYMENT_UNAVAILABLE',
                            'Source environment has no current verified healthy deployment.',
                            $json,
                            JENX_ENV_EXIT_DEPENDENCY
                        );
                    }

                    $requestedDigest = jenxEnvValidateDigest(
                        (string) ($sourceDeployment['digest'] ?? '')
                    );

                    $targetState = jenxEnvReadState($target);
                    $previousDigest = $targetState['observed']['digest'] ?? null;

                    $operation = jenxEnvCreateOperation(
                        'promote',
                        $target,
                        $source,
                        $requestedDigest,
                        $previousDigest
                    );

                    $operation = jenxEnvTransitionOperation(
                        $operation,
                        'APPLYING'
                    );

                    try {
                        $adapterResult = jenxEnvRunDeploymentAdapter(
                            $target,
                            $requestedDigest
                        );

                        $verification = $adapterResult['verification'] ?? [
                            'image_digest_matches' => false,
                            'application_healthy' => false,
                        ];

                        if (($adapterResult['ok'] ?? false) !== true) {
                            $operation = jenxEnvTransitionOperation(
                                $operation,
                                'VERIFYING',
                                [
                                    'verification' => [
                                        'image_digest_matches' =>
                                            (bool) ($verification['image_digest_matches'] ?? false),
                                        'application_healthy' =>
                                            (bool) ($verification['application_healthy'] ?? false),
                                    ],
                                ]
                            );

                            $operation = jenxEnvTransitionOperation(
                                $operation,
                                'FAILED',
                                [
                                    'error_code' =>
                                        (string) ($adapterResult['error_code'] ?? 'DEPLOYMENT_FAILED'),
                                    'error_message' =>
                                        (string) ($adapterResult['error_message'] ?? 'Promotion adapter reported failure.'),
                                ]
                            );

                            jenxEnvCliEmit(
                                [
                                    'schema_version' => 1,
                                    'ok' => false,
                                    'operation' => $operation,
                                    'adapter' => $adapterResult,
                                ],
                                $json,
                                JENX_ENV_EXIT_FAILED
                            );
                        }

                        $operation = jenxEnvTransitionOperation(
                            $operation,
                            'VERIFYING',
                            [
                                'verification' => [
                                    'image_digest_matches' =>
                                        (bool) ($verification['image_digest_matches'] ?? false),
                                    'application_healthy' =>
                                        (bool) ($verification['application_healthy'] ?? false),
                                ],
                            ]
                        );

                        $operation = jenxEnvTransitionOperation(
                            $operation,
                            'SUCCEEDED'
                        );

                        jenxEnvRecordSuccessfulDeployment(
                            $target,
                            $operation,
                            $adapterResult
                        );

                        jenxEnvCliEmit(
                            [
                                'schema_version' => 1,
                                'ok' => true,
                                'operation' => $operation,
                                'adapter' => $adapterResult,
                            ],
                            $json,
                            JENX_ENV_EXIT_OK
                        );

                    } catch (Throwable $exception) {
                        try {
                            $operation = jenxEnvTransitionOperation(
                                $operation,
                                'RECONCILIATION_REQUIRED',
                                [
                                    'error_code' => 'DEPLOYMENT_OUTCOME_UNRESOLVED',
                                    'error_message' => $exception->getMessage(),
                                ]
                            );
                        } catch (Throwable $transitionException) {
                            error_log(
                                'Jen-X promotion operation could not be marked unresolved: ' .
                                $transitionException->getMessage()
                            );
                        }

                        jenxEnvCliEmit(
                            [
                                'schema_version' => 1,
                                'ok' => false,
                                'operation' => $operation,
                                'error' => [
                                    'code' => 'DEPLOYMENT_OUTCOME_UNRESOLVED',
                                    'message' => $exception->getMessage(),
                                ],
                            ],
                            $json,
                            JENX_ENV_EXIT_UNRESOLVED
                        );
                    }

        case 'rollback':
            if (count($positionals) !== 2) {
                throw new InvalidArgumentException('rollback requires an environment.');
            }

            $environment = jenxEnvValidateName($positionals[1]);

            if ($environment === 'prod' && !$confirmProd) {
                jenxEnvCliError(
                    'PROD_CONFIRMATION_REQUIRED',
                    'Rollback of PROD requires --confirm-prod.',
                    $json,
                    JENX_ENV_EXIT_ARGUMENT
                );
            }

            $eligibility = jenxEnvEligibility($environment);

            if (!$eligibility['actions']['rollback']['available']) {
                jenxEnvCliError(
                    'ACTION_NOT_ELIGIBLE',
                    $eligibility['actions']['rollback']['reason'] ?? 'Rollback is unavailable.',
                    $json,
                    JENX_ENV_EXIT_INELIGIBLE
                );
            }

            $state = jenxEnvReadState($environment);

            if ($state === null) {
                jenxEnvCliError(
                    'ENVIRONMENT_STATE_UNAVAILABLE',
                    'Environment state is unavailable.',
                    $json,
                    JENX_ENV_EXIT_DEPENDENCY
                );
            }

            $previousDeployment = jenxEnvFindDeployment(
                $state,
                $state['previous_healthy_deployment_id'] ?? null
            );

            if ($previousDeployment === null
                || ($previousDeployment['verified_healthy'] ?? false) !== true
            ) {
                jenxEnvCliError(
                    'ROLLBACK_DEPLOYMENT_UNAVAILABLE',
                    'No previous verified healthy deployment is available for rollback.',
                    $json,
                    JENX_ENV_EXIT_DEPENDENCY
                );
            }

            $requestedDigest = jenxEnvValidateDigest(
                (string) ($previousDeployment['digest'] ?? '')
            );

            $previousDigest = $state['observed']['digest'] ?? null;

            $operation = jenxEnvCreateOperation(
                'rollback',
                $environment,
                null,
                $requestedDigest,
                $previousDigest
            );

            $operation = jenxEnvTransitionOperation(
                $operation,
                'APPLYING'
            );

            try {
                $adapterResult = jenxEnvRunDeploymentAdapter(
                    $environment,
                    $requestedDigest
                );

                $verification = $adapterResult['verification'] ?? [
                    'image_digest_matches' => false,
                    'application_healthy' => false,
                ];

                if (($adapterResult['ok'] ?? false) !== true) {
                    $operation = jenxEnvTransitionOperation(
                        $operation,
                        'VERIFYING',
                        [
                            'verification' => [
                                'image_digest_matches' =>
                                    (bool) ($verification['image_digest_matches'] ?? false),
                                'application_healthy' =>
                                    (bool) ($verification['application_healthy'] ?? false),
                            ],
                        ]
                    );

                    $operation = jenxEnvTransitionOperation(
                        $operation,
                        'FAILED',
                        [
                            'error_code' =>
                                (string) ($adapterResult['error_code'] ?? 'ROLLBACK_FAILED'),
                            'error_message' =>
                                (string) ($adapterResult['error_message'] ?? 'Rollback adapter reported failure.'),
                        ]
                    );

                    jenxEnvCliEmit(
                        [
                            'schema_version' => 1,
                            'ok' => false,
                            'operation' => $operation,
                            'adapter' => $adapterResult,
                        ],
                        $json,
                        JENX_ENV_EXIT_FAILED
                    );
                }

                $operation = jenxEnvTransitionOperation(
                    $operation,
                    'VERIFYING',
                    [
                        'verification' => [
                            'image_digest_matches' =>
                                (bool) ($verification['image_digest_matches'] ?? false),
                            'application_healthy' =>
                                (bool) ($verification['application_healthy'] ?? false),
                        ],
                    ]
                );

                $operation = jenxEnvTransitionOperation(
                    $operation,
                    'SUCCEEDED'
                );

                jenxEnvRecordSuccessfulDeployment(
                    $environment,
                    $operation,
                    $adapterResult
                );

                jenxEnvCliEmit(
                    [
                        'schema_version' => 1,
                        'ok' => true,
                        'operation' => $operation,
                        'adapter' => $adapterResult,
                    ],
                    $json,
                    JENX_ENV_EXIT_OK
                );

            } catch (Throwable $exception) {
                try {
                    $operation = jenxEnvTransitionOperation(
                        $operation,
                        'RECONCILIATION_REQUIRED',
                        [
                            'error_code' => 'DEPLOYMENT_OUTCOME_UNRESOLVED',
                            'error_message' => $exception->getMessage(),
                        ]
                    );
                } catch (Throwable $transitionException) {
                    error_log(
                        'Jen-X rollback operation could not be marked unresolved: ' .
                        $transitionException->getMessage()
                    );
                }

                jenxEnvCliEmit(
                    [
                        'schema_version' => 1,
                        'ok' => false,
                        'operation' => $operation,
                        'error' => [
                            'code' => 'DEPLOYMENT_OUTCOME_UNRESOLVED',
                            'message' => $exception->getMessage(),
                        ],
                    ],
                    $json,
                    JENX_ENV_EXIT_UNRESOLVED
                );
            }
    }
} catch (InvalidArgumentException $exception) {
    jenxEnvCliError('INVALID_ARGUMENT', $exception->getMessage(), $json, JENX_ENV_EXIT_ARGUMENT);
} catch (Throwable $exception) {
    error_log('Jen-X environment CLI: ' . get_class($exception) . ': ' . $exception->getMessage());
    jenxEnvCliError('STATE_UNAVAILABLE', 'Environment state is unavailable or failed validation.', $json, JENX_ENV_EXIT_DEPENDENCY);
}
