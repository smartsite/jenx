<?php

declare(strict_types=1);

/**
 * Jen-X Build Status API
 *
 * Read-only endpoint.
 * The controller owns build-state access.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function jenxBuildStatusRespond(int $httpStatus, array $payload): never
{
    http_response_code($httpStatus);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');

    jenxBuildStatusRespond(405, [
        'ok' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Only GET requests are supported.',
        ],
    ]);
}

$requestId = $_GET['request_id'] ?? null;

if (!is_string($requestId) || $requestId === '') {
    jenxBuildStatusRespond(400, [
        'ok' => false,
        'error' => [
            'code' => 'INVALID_REQUEST_ID',
            'message' => 'A valid request_id is required.',
        ],
    ]);
}

if (!preg_match(
    '/^[0-9a-fA-F-]{36}$/',
    $requestId
)) {
    jenxBuildStatusRespond(400, [
        'ok' => false,
        'error' => [
            'code' => 'INVALID_REQUEST_ID',
            'message' => 'A valid request_id is required.',
        ],
    ]);
}

$socket = '/run/jenx/web.sock';

/*
if (!is_socket($socket)) {
    jenxBuildStatusRespond(503, [
        'ok' => false,
        'error' => [
            'code' => 'BRIDGE_UNAVAILABLE',
            'message' => 'Jen-X web bridge is unavailable.',
        ],
    ]);
}
*/

$payload = json_encode([
    'command' => [
        'build-status',
        $requestId,
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$client = @stream_socket_client(
    'unix://' . $socket,
    $errno,
    $errstr,
    2
);

if ($client === false) {
    jenxBuildStatusRespond(503, [
        'ok' => false,
        'error' => [
            'code' => 'BRIDGE_UNAVAILABLE',
            'message' => 'Unable to connect to the Jen-X web bridge.',
        ],
    ]);
}

stream_set_timeout($client, 5);

if (fwrite($client, $payload . "\n") === false) {
    fclose($client);

    jenxBuildStatusRespond(502, [
        'ok' => false,
        'error' => [
            'code' => 'BRIDGE_WRITE_FAILED',
            'message' => 'Unable to send the build-status request.',
        ],
    ]);
}

$response = '';

while (!feof($client)) {
    $line = fgets($client);

    if ($line === false) {
        break;
    }

    $response .= $line;

    if (str_ends_with($line, "\n")) {
        break;
    }
}

fclose($client);

if ($response === '') {
    jenxBuildStatusRespond(502, [
        'ok' => false,
        'error' => [
            'code' => 'BRIDGE_EMPTY_RESPONSE',
            'message' => 'The Jen-X web bridge returned no response.',
        ],
    ]);
}

try {
    $bridge = json_decode(
        trim($response),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    error_log(
        'Jen-X build status API: invalid bridge response ('
        . get_class($exception) . ')'
    );

    jenxBuildStatusRespond(502, [
        'ok' => false,
        'error' => [
            'code' => 'BRIDGE_INVALID_RESPONSE',
            'message' => 'The Jen-X web bridge returned an invalid response.',
        ],
    ]);
}

if (($bridge['ok'] ?? false) !== true) {
    $message = $bridge['stderr'] ?? 'Jen-X build status command failed.';

    jenxBuildStatusRespond(502, [
        'ok' => false,
        'error' => [
            'code' => 'BRIDGE_FAILED',
            'message' => $message,
        ],
    ]);
}

try {
    $result = json_decode(
        trim((string) ($bridge['stdout'] ?? '')),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    error_log(
        'Jen-X build status API: invalid controller response ('
        . get_class($exception) . ')'
    );

    jenxBuildStatusRespond(502, [
        'ok' => false,
        'error' => [
            'code' => 'CONTROLLER_INVALID_RESPONSE',
            'message' => 'The Jen-X controller returned an invalid response.',
        ],
    ]);
}

jenxBuildStatusRespond(200, $result);
