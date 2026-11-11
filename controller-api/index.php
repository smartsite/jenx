<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function apiFail(string $code, string $message, int $status = 400): never
{
    http_response_code($status);

    echo json_encode([
        'schema_version' => 1,
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    apiFail('METHOD_NOT_ALLOWED', 'Only POST requests are allowed.', 405);
}

$raw = file_get_contents('php://input');

if ($raw === false || trim($raw) === '') {
    apiFail('INVALID_REQUEST', 'Request body is required.');
}

try {
    $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    apiFail('INVALID_JSON', 'Request body must contain valid JSON.');
}

if (!is_array($request)) {
    apiFail('INVALID_REQUEST', 'Request body must be a JSON object.');
}

$action = $request['action'] ?? null;

if (!is_string($action)) {
    apiFail('INVALID_ACTION', 'Action is required.');
}

$args = [];

switch ($action) {

    case 'deploy':
        $environment = $request['environment'] ?? null;
        $digest = $request['digest'] ?? null;

        if (
            !is_string($environment)
            || !in_array($environment, ['dev', 'uat', 'prod'], true)
        ) {
            apiFail('INVALID_ENVIRONMENT', 'Environment must be dev, uat, or prod.');
        }

        if (
            !is_string($digest)
            || preg_match('/^sha256:[a-f0-9]{64}$/', $digest) !== 1
        ) {
            apiFail('INVALID_DIGEST', 'Invalid image digest.');
        }

        $args = [
            'deploy',
            $environment,
            $digest,
        ];

        if (($request['confirm_prod'] ?? false) === true) {
            $args[] = '--confirm-prod';
        }

        break;

    case 'promote':
        $source = $request['source'] ?? null;
        $target = $request['target'] ?? null;

        if (
            !is_string($source)
            || !in_array($source, ['dev', 'uat', 'prod'], true)
            || !is_string($target)
            || !in_array($target, ['dev', 'uat', 'prod'], true)
        ) {
            apiFail('INVALID_ENVIRONMENT', 'Invalid promotion environments.');
        }

        if ($source === $target) {
            apiFail('INVALID_PROMOTION', 'Source and target must differ.');
        }

        $args = [
            'promote',
            $source,
            $target,
        ];

        if (($request['confirm_prod'] ?? false) === true) {
            $args[] = '--confirm-prod';
        }

        break;

    case 'rollback':
        $environment = $request['environment'] ?? null;

        if (
            !is_string($environment)
            || !in_array($environment, ['dev', 'uat', 'prod'], true)
        ) {
            apiFail('INVALID_ENVIRONMENT', 'Environment must be dev, uat, or prod.');
        }

        $args = [
            'rollback',
            $environment,
        ];

        if (($request['confirm_prod'] ?? false) === true) {
            $args[] = '--confirm-prod';
        }

        break;

    default:
        apiFail(
            'INVALID_ACTION',
            'Action must be deploy, promote, or rollback.'
        );
}

$command = [
    PHP_BINARY,
    __DIR__ . '/../jenx-api.php',
    ...$args,
];

$escaped = array_map('escapeshellarg', $command);

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
    apiFail('CONTROLLER_START_FAILED', 'Unable to start Jen-X controller.', 500);
}

fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);

fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);

if ($stdout !== '') {
    $decoded = json_decode($stdout, true);

    if (is_array($decoded)) {
        http_response_code($exitCode === 0 ? 200 : 422);

        echo json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}

apiFail(
    'CONTROLLER_FAILED',
    trim($stderr) !== '' ? trim($stderr) : 'Controller returned no JSON.',
    500
);
