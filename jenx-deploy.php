<?php
declare(strict_types=1);

/**
 * Jen-X FOUNDD deployment adapter
 *
 * This script is deliberately unaware of Jen-X's operation/state machinery.
 * It performs one concrete deployment action against a FOUNDD Compose
 * environment and reports what actually happened.
 *
 * Usage:
 *   php jenx-deploy.php deploy <env> <sha256:digest> [--json]
 *
 * Environment:
 *   JENX_FOUNDD_BASE_DIR
 *       Base directory containing dev/, uat/, prod/.
 *       Defaults to /opt/foundd.
 */

const EXIT_OK          = 0;
const EXIT_ARGUMENT    = 2;
const EXIT_DEPENDENCY  = 7;
const EXIT_FAILED      = 4;
const EXIT_UNRESOLVED  = 5;

const FOUNDD_IMAGE = 'ghcr.io/humnn-innovations/foundd';
const FOUNDD_HEALTH_URLS = [
    'dev'  => 'https://dev.founddmatch.com/api/health',
    'uat'  => 'https://uat.founddmatch.com/api/health',
    'prod' => 'https://founddmatch.com/api/health',
];

$baseDir = getenv('JENX_FOUNDD_BASE_DIR');
if ($baseDir === false || trim($baseDir) === '') {
    $baseDir = '/opt/foundd';
}

$argv0 = $argv[0] ?? 'jenx-deploy.php';

function usage(string $argv0): void
{
    fwrite(STDERR, <<<TXT
Usage:
  php {$argv0} deploy <env> <sha256:digest> [--json]

Arguments:
  env       dev | uat | prod
  digest    Full immutable SHA-256 digest

Options:
  --json    Emit machine-readable JSON

Environment:
  JENX_FOUNDD_BASE_DIR
            FOUNDD deployment root (default: /opt/foundd)

TXT);
}

function failResult(
    string $environment,
    string $requestedDigest,
    string $errorCode,
    string $errorMessage,
    bool $json
): never {
    $result = [
        'ok' => false,
        'environment' => $environment,
        'requested_digest' => $requestedDigest,
        'error_code' => $errorCode,
        'error_message' => $errorMessage,
    ];

    emitResult($result, $json);

    $exit = match ($errorCode) {
        'DEPLOYMENT_CONFIG_INVALID',
        'INVALID_ARGUMENT' => EXIT_ARGUMENT,

        'DOCKER_NOT_AVAILABLE',
        'COMPOSE_NOT_AVAILABLE' => EXIT_DEPENDENCY,

        'DEPLOYMENT_RECONCILIATION_REQUIRED' => EXIT_UNRESOLVED,

        default => EXIT_FAILED,
    };

    exit($exit);
}

function emitResult(array $result, bool $json): void
{
    if ($json) {
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        ) . PHP_EOL;

        return;
    }

    if (($result['ok'] ?? false) === true) {
        echo "Deployment succeeded\n";
        echo "Environment: {$result['environment']}\n";
        echo "Requested:   {$result['requested_digest']}\n";

        $observed = $result['observed'] ?? [];

        echo "Container:   " . ($observed['container_state'] ?? 'unknown') . "\n";
        echo "Image:       " . ($observed['image'] ?? 'unknown') . "\n";
        echo "Digest:      " . ($observed['digest'] ?? 'unknown') . "\n";
        echo "Health:      " . (($observed['application_healthy'] ?? false) ? 'healthy' : 'unhealthy') . "\n";

        return;
    }

    fwrite(
        STDERR,
        "Deployment failed\n" .
        "Environment: {$result['environment']}\n" .
        "Requested:   {$result['requested_digest']}\n" .
        "Error:       {$result['error_code']}\n" .
        "Message:     {$result['error_message']}\n"
    );
}

function isValidEnvironment(string $environment): bool
{
    return in_array($environment, ['dev', 'uat', 'prod'], true);
}

function isValidDigest(string $digest): bool
{
    return preg_match(
        '/^sha256:[a-f0-9]{64}$/',
        $digest
    ) === 1;
}

function runCommand(
    array $command,
    ?string $cwd,
    array &$output,
    int &$exitCode
): bool {
    $escaped = array_map(
        static fn(string $arg): string => escapeshellarg($arg),
        $command
    );

    $commandLine = implode(' ', $escaped);

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $commandLine,
        $descriptorSpec,
        $pipes,
        $cwd
    );

    if (!is_resource($process)) {
        $output = [];
        $exitCode = -1;
        return false;
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    $output = [
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];

    return true;
}

function composeCommand(string $deploymentDir, array $arguments): array
{
    return array_merge(
        [
            'docker',
            'compose',
            '--project-directory',
            $deploymentDir,
        ],
        $arguments
    );
}

function readEnvDigest(string $envFile): ?string
{
    if (!is_file($envFile) || !is_readable($envFile)) {
        return null;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return null;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^FOUNDD_DIGEST=(.*)$/', $line, $matches) === 1) {
            return trim($matches[1]);
        }
    }

    return null;
}

function updateEnvDigest(string $envFile, string $newDigest): void
{
    if (!is_file($envFile) || !is_readable($envFile) || !is_writable($envFile)) {
        throw new RuntimeException('Live .env file is not readable/writable');
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new RuntimeException('Unable to read live .env file');
    }

    $found = false;

    foreach ($lines as $i => $line) {
        if (preg_match('/^(\s*FOUNDD_DIGEST\s*=).*/', $line) === 1) {
            $lines[$i] = "FOUNDD_DIGEST={$newDigest}";
            $found = true;
            break;
        }
    }

    if (!$found) {
        throw new RuntimeException(
            'Live .env does not contain FOUNDD_DIGEST'
        );
    }

    $contents = implode(PHP_EOL, $lines) . PHP_EOL;

    $tmp = $envFile . '.jenx-' . bin2hex(random_bytes(6));

    if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary .env file');
    }

    /*
     * Preserve the existing .env permissions.
     */
    $mode = fileperms($envFile);
    if ($mode !== false) {
        chmod($tmp, $mode & 0777);
    }

    if (!rename($tmp, $envFile)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to replace live .env file');
    }
}

function getContainerName(string $deploymentDir): string
{
    $output = [];
    $exitCode = 0;

    $ok = runCommand(
        composeCommand(
            $deploymentDir,
            [
                'ps',
                '-q',
                'app',
            ]
        ),
        $deploymentDir,
        $output,
        $exitCode
    );

    if (!$ok || $exitCode !== 0) {
        throw new RuntimeException(
            'Unable to determine app container: ' .
            ($output['stderr'] ?? 'unknown error')
        );
    }

    $containerId = trim($output['stdout']);

    if ($containerId === '') {
        throw new RuntimeException('Compose returned no app container');
    }

    return $containerId;
}

function ensurePostgresHealthy(string $deploymentDir, int $timeoutSeconds = 60): void
{
    $output = [];
    $exitCode = 0;

    // Ensure the independently managed PostgreSQL service exists.
    if (
        !runCommand(
            composeCommand(
                $deploymentDir,
                [
                    'up',
                    '-d',
                    'postgres',
                ]
            ),
            $deploymentDir,
            $output,
            $exitCode
        ) ||
        $exitCode !== 0
    ) {
        throw new RuntimeException(
            'Unable to start PostgreSQL: ' .
            (
                ($output['stderr'] ?? '') !== ''
                    ? $output['stderr']
                    : ($output['stdout'] ?? 'unknown error')
            )
        );
    }

    // Wait until PostgreSQL reports healthy.
    $deadline = time() + $timeoutSeconds;

    do {
        $output = [];
        $exitCode = 0;

        if (
            runCommand(
                composeCommand(
                    $deploymentDir,
                    [
                        'ps',
                        '-q',
                        'postgres',
                    ]
                ),
                $deploymentDir,
                $output,
                $exitCode
            ) &&
            $exitCode === 0
        ) {
            $containerId = trim($output['stdout']);

            if ($containerId !== '') {
                $inspect = [];
                $inspectExitCode = 0;

                if (
                    runCommand(
                        [
                            'docker',
                            'inspect',
                            '--format',
                            '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{end}}',
                            $containerId,
                        ],
                        null,
                        $inspect,
                        $inspectExitCode
                    ) &&
                    $inspectExitCode === 0
                ) {
                    $state = trim($inspect['stdout']);

                    if ($state === 'running|healthy') {
                        return;
                    }

                    if (str_starts_with($state, 'exited|')) {
                        throw new RuntimeException(
                            'PostgreSQL container exited before becoming healthy'
                        );
                    }
                }
            }
        }

        sleep(2);
    } while (time() < $deadline);

    throw new RuntimeException(
        'PostgreSQL did not become healthy within ' .
        $timeoutSeconds .
        ' seconds'
    );
}

function inspectContainer(string $containerId): array
{
    $output = [];
    $exitCode = 0;

    $format = '{{json .}}';

    $ok = runCommand(
        [
            'docker',
            'inspect',
            '--format',
            $format,
            $containerId,
        ],
        null,
        $output,
        $exitCode
    );

    if (!$ok || $exitCode !== 0 || trim($output['stdout']) === '') {
        throw new RuntimeException(
            'Unable to inspect app container: ' .
            ($output['stderr'] ?? 'unknown error')
        );
    }

    $data = json_decode(
        $output['stdout'],
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    return $data;
}

function checkApplicationHealth(string $environment): bool
{
    $url = FOUNDD_HEALTH_URLS[$environment] ?? null;

    if ($url === null) {
        return false;
    }

    $output = [];
    $exitCode = 0;

    if (
        !runCommand(
            [
                'curl',
                '--fail',
                '--silent',
                '--show-error',
                '--max-time',
                '10',
                $url,
            ],
            null,
            $output,
            $exitCode
        ) ||
        $exitCode !== 0
    ) {
        return false;
    }

    $response = json_decode(
        $output['stdout'],
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    return
        ($response['status'] ?? null) === 'ok' &&
        ($response['app'] ?? null) === 'available';
}

function extractObservedState(array $inspect): array
{
    $state = $inspect['State'] ?? [];
    $config = $inspect['Config'] ?? [];
    $repoDigests = $inspect['RepoDigests'] ?? [];

    $containerState = strtolower((string)($state['Status'] ?? 'unknown'));

    $health = 'unknown';

    if (isset($state['Health']['Status'])) {
        $health = strtolower((string)$state['Health']['Status']);
    }

    $image = (string)($config['Image'] ?? '');

    $digest = null;

    /*
     * For an image pulled by digest, Config.Image should contain the
     * immutable reference. RepoDigests provides an additional observation
     * of the image content.
     */
    if (preg_match('/@(sha256:[a-f0-9]{64})$/', $image, $matches) === 1) {
        $digest = $matches[1];
    }

    if ($digest === null) {
        foreach ($repoDigests as $repoDigest) {
            if (
                preg_match(
                    '/@(' . 'sha256:[a-f0-9]{64})$/',
                    (string)$repoDigest,
                    $matches
                ) === 1
            ) {
                $digest = $matches[1];
                break;
            }
        }
    }

    return [
        'container_state' => $containerState,
        'image' => $image,
        'digest' => $digest,
        'health' => $health,
    ];
}

function waitForContainerHealthy(
    string $containerId,
    string $environment,
    int $timeoutSeconds = 60
): array {
    $deadline = time() + $timeoutSeconds;
    $lastObserved = [
        'container_state' => 'unknown',
        'image' => '',
        'digest' => null,
        'health' => 'unknown',
    ];

    do {
        $inspect = inspectContainer($containerId);
        $lastObserved = extractObservedState($inspect);

       if ($lastObserved['container_state'] === 'running') {
            if (checkApplicationHealth($environment)) {
                return [
                    ...$lastObserved,
                    'application_healthy' => true,
                ];
            }
        }

        if ($lastObserved['container_state'] === 'exited') {
            return $lastObserved;
        }

        sleep(2);
    } while (time() < $deadline);

    return $lastObserved;
}


/*
 * Main
 */

$json = in_array('--json', $argv, true);

$positionals = array_values(
    array_filter(
        array_slice($argv, 1),
        static fn(string $arg): bool => $arg !== '--json'
    )
);

if (count($positionals) !== 3 || $positionals[0] !== 'deploy') {
    usage($argv0);
    exit(EXIT_ARGUMENT);
}

$environment = $positionals[1];
$requestedDigest = $positionals[2];

if (!isValidEnvironment($environment)) {
    failResult(
        $environment,
        $requestedDigest,
        'INVALID_ARGUMENT',
        'Environment must be dev, uat or prod',
        $json
    );
}

if (!isValidDigest($requestedDigest)) {
    failResult(
        $environment,
        $requestedDigest,
        'INVALID_ARGUMENT',
        'Digest must be a full sha256:<64 hex> value',
        $json
    );
}

$deploymentDir = rtrim($baseDir, '/') . '/' . $environment;
$envFile = $deploymentDir . '/.env';
$composeFile = $deploymentDir . '/compose.yaml';

if (!is_dir($deploymentDir)) {
    failResult(
        $environment,
        $requestedDigest,
        'DEPLOYMENT_CONFIG_INVALID',
        "Deployment directory does not exist: {$deploymentDir}",
        $json
    );
}

if (!is_file($composeFile) || !is_readable($composeFile)) {
    failResult(
        $environment,
        $requestedDigest,
        'DEPLOYMENT_CONFIG_INVALID',
        "Compose file is missing or unreadable: {$composeFile}",
        $json
    );
}

if (!is_file($envFile) || !is_readable($envFile) || !is_writable($envFile)) {
    failResult(
        $environment,
        $requestedDigest,
        'DEPLOYMENT_CONFIG_INVALID',
        "Live .env is missing or not readable/writable: {$envFile}",
        $json
    );
}


/*
 * Verify Docker and Compose before touching the live configuration.
 */

$output = [];
$exitCode = 0;

if (
    !runCommand(
        ['docker', '--version'],
        null,
        $output,
        $exitCode
    ) ||
    $exitCode !== 0
) {
    failResult(
        $environment,
        $requestedDigest,
        'DOCKER_NOT_AVAILABLE',
        'Docker CLI is unavailable',
        $json
    );
}

if (
    !runCommand(
        ['docker', 'compose', 'version'],
        null,
        $output,
        $exitCode
    ) ||
    $exitCode !== 0
) {
    failResult(
        $environment,
        $requestedDigest,
        'COMPOSE_NOT_AVAILABLE',
        'Docker Compose is unavailable',
        $json
    );
}


/*
 * Verify that the Compose project is actually the expected FOUNDD project.
 *
 * We deliberately inspect the rendered configuration before deployment.
 */

$output = [];
$exitCode = 0;

if (
    !runCommand(
        composeCommand(
            $deploymentDir,
            [
                'config',
                '--images',
            ]
        ),
        $deploymentDir,
        $output,
        $exitCode
    ) ||
    $exitCode !== 0
) {
    failResult(
        $environment,
        $requestedDigest,
        'DEPLOYMENT_CONFIG_INVALID',
        'docker compose config --images failed: ' .
        ($output['stderr'] ?? 'unknown error'),
        $json
    );
}

$images = preg_split(
    '/\R+/',
    trim($output['stdout']),
    -1,
    PREG_SPLIT_NO_EMPTY
);

$expectedImage = FOUNDD_IMAGE;

$foundExpectedImage = false;

foreach ($images as $image) {
    $imageRepository = $image;

    $atPos = strpos($imageRepository, '@');

    if ($atPos !== false) {
        $imageRepository = substr($imageRepository, 0, $atPos);
    }

    if ($imageRepository === $expectedImage) {
        $foundExpectedImage = true;
        break;
    }
}

if (!$foundExpectedImage) {
    throw new RuntimeException(
        'Compose configuration does not reference the expected FOUNDD image'
    );
}


/*
 * Read current digest.
 *
 * We require FOUNDD_DIGEST to exist. The adapter does not invent or repair
 * deployment configuration.
 */

$currentDigest = readEnvDigest($envFile);

if ($currentDigest === null || !isValidDigest($currentDigest)) {
    failResult(
        $environment,
        $requestedDigest,
        'DEPLOYMENT_CONFIG_INVALID',
        'FOUNDD_DIGEST is missing or invalid in the live .env',
        $json
    );
}


/*
 * Update the live digest to the requested immutable image.
 *
 * Compose resolves image: ${FOUNDD_IMAGE}@${FOUNDD_DIGEST} from this
 * live .env, so the digest must be changed before pulling.
 *
 * If the subsequent pull fails, the live configuration represents the
 * requested desired digest but the running container remains unchanged.
 * The caller can therefore treat the result as a failed deployment and
 * reconcile the observed runtime state.
 */

try {
    updateEnvDigest($envFile, $requestedDigest);
} catch (Throwable $e) {
    failResult(
        $environment,
        $requestedDigest,
        'DEPLOYMENT_CONFIG_INVALID',
        $e->getMessage(),
        $json
    );
}


/*
 * Pull the exact immutable image.
 */

/*
 * Ensure the independently managed PostgreSQL service exists and is healthy.
 *
 * This is required for the first deployment of a fresh environment.
 * Existing PostgreSQL containers are left running and are not restarted
 * as part of the application deployment.
 */
try {
    ensurePostgresHealthy($deploymentDir);
} catch (Throwable $e) {
    failResult(
        $environment,
        $requestedDigest,
        'DEPLOYMENT_RECONCILIATION_REQUIRED',
        $e->getMessage(),
        $json
    );
}


/*
 * Recreate/update only the app service.
 *
 * --no-deps is intentional. PostgreSQL belongs to the independently
 * managed Compose project and must not be restarted as part of an app
 * image deployment.
 */

$output = [];
$exitCode = 0;

if (
    !runCommand(
        composeCommand(
            $deploymentDir,
            [
                'up',
                '-d',
                '--no-deps',
                'app',
            ]
        ),
        $deploymentDir,
        $output,
        $exitCode
    ) ||
    $exitCode !== 0
) {
    failResult(
        $environment,
        $requestedDigest,
        'DOCKER_APPLY_FAILED',
        trim(
            ($output['stderr'] ?? '') !== ''
                ? $output['stderr']
                : ($output['stdout'] ?? '')
        ),
        $json
    );
}


/*
 * Compose should now have a container. Observe it rather than assuming
 * success from the exit code.
 */

try {
    $containerId = getContainerName($deploymentDir);
} catch (Throwable $e) {
    failResult(
        $environment,
        $requestedDigest,
        'DEPLOYMENT_RECONCILIATION_REQUIRED',
        $e->getMessage(),
        $json
    );
}

try {
   $observed = waitForContainerHealthy($containerId, $environment);
} catch (Throwable $e) {
    failResult(
        $environment,
        $requestedDigest,
        'DEPLOYMENT_RECONCILIATION_REQUIRED',
        $e->getMessage(),
        $json
    );
}

$digestMatches =  $observed['digest'] === $requestedDigest;
$applicationHealthy = $observed['application_healthy'] === true;

$result = [
    'ok' => $digestMatches && $applicationHealthy,
    'environment' => $environment,
    'requested_digest' => $requestedDigest,
    'observed' => $observed,
    'verification' => [
        'image_digest_matches' => $digestMatches,
        'application_healthy' => $applicationHealthy,
    ],
];

if (!$digestMatches) {
    $result['error_code'] = 'IMAGE_DIGEST_MISMATCH';
    $result['error_message'] =
        'Observed image digest does not match requested digest';

    emitResult($result, $json);
    exit(EXIT_UNRESOLVED);
}

if (!$applicationHealthy) {
    $result['error_code'] = 'APPLICATION_UNHEALTHY';
    $result['error_message'] =
        'App container did not reach running/healthy state';

    emitResult($result, $json);
    exit(EXIT_FAILED);
}

emitResult($result, $json);
exit(EXIT_OK);
