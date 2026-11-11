<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function deploymentActionFail(string $code, string $message, int $status = 400): never
{
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

function deploymentActionRunBridge(array $arguments): never
{
    $socket = '/run/jenx/web.sock';

    $connection = @stream_socket_client(
        'unix://' . $socket,
        $errno,
        $errstr,
        10
    );

    if ($connection === false) {
        deploymentActionFail(
            'BRIDGE_CONNECTION_FAILED',
            'Unable to connect to the Jen-X deployment gateway.',
            502
        );
    }

    $payload = json_encode([
        'command' => $arguments,
    ], JSON_THROW_ON_ERROR);

    fwrite($connection, $payload);

    $response = stream_get_contents($connection);

    fclose($connection);

    if ($response === false || trim($response) === '') {
        deploymentActionFail(
            'BRIDGE_EMPTY_RESPONSE',
            'The Jen-X deployment gateway returned no response.',
            502
        );
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        deploymentActionFail(
            'BRIDGE_INVALID_RESPONSE',
            'The Jen-X deployment gateway returned invalid JSON.',
            502
        );
    }

    if (
        !isset($decoded['ok']) ||
        $decoded['ok'] !== true
    ) {
        deploymentActionFail(
            'BRIDGE_FAILED',
            isset($decoded['error'])
                ? (string) $decoded['error']
                : 'The Jen-X deployment gateway failed.',
            500
        );
    }

    $stdout = $decoded['stdout'] ?? '';

    if ($stdout !== '') {
        $result = json_decode($stdout, true);

        if (is_array($result)) {
            http_response_code(200);

            echo json_encode(
                $result,
                JSON_PRETTY_PRINT |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );

            exit;
        }
    }

    deploymentActionFail(
        'BRIDGE_INVALID_RESPONSE',
        'The Jen-X deployment bridge did not return deployment JSON.',
        500
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    deploymentActionFail(
        'METHOD_NOT_ALLOWED',
        'Only POST requests are allowed.',
        405
    );
}

$raw = file_get_contents('php://input');

if ($raw === false || trim($raw) === '') {
    deploymentActionFail(
        'INVALID_REQUEST',
        'Request body is required.'
    );
}

try {
    $request = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    deploymentActionFail(
        'INVALID_JSON',
        'Request body must contain valid JSON.'
    );
}

if (!is_array($request)) {
    deploymentActionFail(
        'INVALID_REQUEST',
        'Request body must be a JSON object.'
    );
}

$action = $request['action'] ?? null;

if (!is_string($action) || !in_array(
    $action,
    ['deploy', 'promote', 'rollback'],
    true
)) {
    deploymentActionFail(
        'INVALID_ACTION',
        'Action must be deploy, promote, or rollback.'
    );
}

$confirmProd = ($request['confirm_prod'] ?? false) === true;

switch ($action) {

    case 'deploy':
        $environment = $request['environment'] ?? null;
        $digest = $request['digest'] ?? null;

        if (
            !is_string($environment)
            || !in_array($environment, ['dev', 'uat', 'prod'], true)
        ) {
            deploymentActionFail(
                'INVALID_ENVIRONMENT',
                'Environment must be dev, uat, or prod.'
            );
        }

        if (
            !is_string($digest)
            || preg_match('/^sha256:[a-f0-9]{64}$/', $digest) !== 1
        ) {
            deploymentActionFail(
                'INVALID_DIGEST',
                'Digest must be in sha256:<64 lowercase hex characters> format.'
            );
        }

        if ($environment === 'prod' && !$confirmProd) {
            deploymentActionFail(
                'PROD_CONFIRMATION_REQUIRED',
                'Production deployment requires explicit confirmation.'
            );
        }

        $bridgeArgs = [
            'deploy',
            $environment,
            $digest,
        ];

        if ($environment === 'prod') {
            $bridgeArgs[] = '--confirm-prod';
        }

        deploymentActionRunBridge($bridgeArgs);

    case 'promote':
        $source = $request['source'] ?? null;
        $target = $request['target'] ?? null;

        if (
            !is_string($source)
            || !in_array($source, ['dev', 'uat', 'prod'], true)
            || !is_string($target)
            || !in_array($target, ['dev', 'uat', 'prod'], true)
        ) {
            deploymentActionFail(
                'INVALID_ENVIRONMENT',
                'Source and target must be dev, uat, or prod.'
            );
        }

        if ($source === $target) {
            deploymentActionFail(
                'INVALID_PROMOTION',
                'Source and target environments must be different.'
            );
        }

        if ($target === 'prod' && !$confirmProd) {
            deploymentActionFail(
                'PROD_CONFIRMATION_REQUIRED',
                'Production promotion requires explicit confirmation.'
            );
        }

        $bridgeArgs = [
            'promote',
            $source,
            $target,
        ];

        if ($target === 'prod') {
            $bridgeArgs[] = '--confirm-prod';
        }

        deploymentActionRunBridge($bridgeArgs);

    case 'rollback':
        $environment = $request['environment'] ?? null;

        if (
            !is_string($environment)
            || !in_array($environment, ['dev', 'uat', 'prod'], true)
        ) {
            deploymentActionFail(
                'INVALID_ENVIRONMENT',
                'Environment must be dev, uat, or prod.'
            );
        }

        if ($environment === 'prod' && !$confirmProd) {
            deploymentActionFail(
                'PROD_CONFIRMATION_REQUIRED',
                'Production rollback requires explicit confirmation.'
            );
        }

        $bridgeArgs = [
            'rollback',
            $environment,
        ];

        if ($environment === 'prod') {
            $bridgeArgs[] = '--confirm-prod';
        }

        deploymentActionRunBridge($bridgeArgs);
}
