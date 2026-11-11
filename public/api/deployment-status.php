<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function deploymentStatusFail(
    string $code,
    string $message,
    int $status = 500
): never {
    http_response_code($status);

    echo json_encode([
        'schema_version' => 1,
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    exit;
}

function deploymentStatusReadBridge(): array
{
    $socket = '/run/jenx/web.sock';

    $connection = @stream_socket_client(
        'unix://' . $socket,
        $errno,
        $errstr,
        10
    );

    if ($connection === false) {
        deploymentStatusFail(
            'BRIDGE_CONNECTION_FAILED',
            'Unable to connect to the Jen-X deployment gateway.',
            502
        );
    }

    $payload = json_encode([
        'command' => ['status'],
    ], JSON_THROW_ON_ERROR);

    fwrite($connection, $payload);

    $response = stream_get_contents($connection);
    fclose($connection);

    if ($response === false || trim($response) === '') {
        deploymentStatusFail(
            'BRIDGE_EMPTY_RESPONSE',
            'The Jen-X deployment gateway returned no response.',
            502
        );
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        deploymentStatusFail(
            'BRIDGE_INVALID_RESPONSE',
            'The Jen-X deployment gateway returned invalid JSON.',
            502
        );
    }

    if (($decoded['ok'] ?? false) !== true) {
        deploymentStatusFail(
            'BRIDGE_FAILED',
            isset($decoded['error'])
                ? (string) $decoded['error']
                : 'The Jen-X deployment gateway failed.',
            500
        );
    }

    $stdout = $decoded['stdout'] ?? '';

    if ($stdout === '') {
        deploymentStatusFail(
            'BRIDGE_EMPTY_RESPONSE',
            'The Jen-X deployment bridge returned no deployment status.',
            502
        );
    }

    $result = json_decode($stdout, true);

    if (!is_array($result)) {
        deploymentStatusFail(
            'BRIDGE_INVALID_RESPONSE',
            'The Jen-X deployment bridge returned invalid deployment JSON.',
            502
        );
    }

    return $result;
}

try {
    $result = deploymentStatusReadBridge();

    http_response_code(200);

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

} catch (Throwable $e) {
    deploymentStatusFail(
        'DEPLOYMENT_STATUS_FAILED',
        $e->getMessage(),
        500
    );
}
