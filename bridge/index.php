<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function fail(string $code, string $message, int $status = 400): never
{
    http_response_code($status);

    echo json_encode([
        'schema_version' => 1,
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ], JSON_UNESCAPED_SLASHES);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    fail('METHOD_NOT_ALLOWED', 'Only POST requests are allowed.', 405);
}

$raw = file_get_contents('php://input');

if ($raw === false || trim($raw) === '') {
    fail('INVALID_REQUEST', 'Request body is required.');
}

try {
    $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fail('INVALID_JSON', 'Request body must contain valid JSON.');
}

if (!is_array($request)) {
    fail('INVALID_REQUEST', 'Request body must be a JSON object.');
}

$arguments = $request['arguments'] ?? null;

if (!is_array($arguments) || $arguments === []) {
    fail('INVALID_ARGUMENTS', 'Bridge arguments are required.');
}

$allowedActions = ['deploy', 'promote', 'rollback'];

$action = $arguments[0] ?? null;

if (!is_string($action) || !in_array($action, $allowedActions, true)) {
    fail('INVALID_ACTION', 'Invalid Jen-X action.');
}

/*
 * This endpoint is intended to be reachable only from the local
 * Docker/web bridge path. It does not accept arbitrary commands.
 */
$command = ['/usr/local/sbin/jenx-web-bridge'];

foreach ($arguments as $argument) {
    if (!is_string($argument)) {
        fail('INVALID_ARGUMENT', 'All bridge arguments must be strings.');
    }

    $command[] = $argument;
}

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
    fail('BRIDGE_START_FAILED', 'Unable to start the Jen-X host bridge.', 500);
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

fail(
    'BRIDGE_FAILED',
    trim($stderr) !== ''
        ? trim($stderr)
        : 'The Jen-X host bridge failed without returning JSON.',
    500
);
