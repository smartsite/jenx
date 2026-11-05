<?php

declare(strict_types=1);

/**
 * Jen-X Build Status API
 *
 * Read-only endpoint. Never contacts GitHub, runs the collector,
 * dispatches builds, or modifies persistent state.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function jenxStatusRespond(int $httpStatus, array $payload): never
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

    jenxStatusRespond(405, [
        'ok' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Only GET requests are supported.',
        ],
    ]);
}

require_once dirname(__DIR__, 2) . '/jenx-build-state.php';

$requestId = $_GET['request_id'] ?? null;

if (!is_string($requestId) || $requestId === '') {
    jenxStatusRespond(400, [
        'ok' => false,
        'error' => [
            'code' => 'INVALID_REQUEST_ID',
            'message' => 'A valid request_id is required.',
        ],
    ]);
}

try {
    $requestId = jenxBuildStateValidateRequestId($requestId);
} catch (InvalidArgumentException $exception) {
    jenxStatusRespond(400, [
        'ok' => false,
        'error' => [
            'code' => 'INVALID_REQUEST_ID',
            'message' => 'A valid request_id is required.',
        ],
    ]);
}

$recordPath = jenxBuildStateRecordPath($requestId);

if (is_link($recordPath) || !is_file($recordPath)) {
    jenxStatusRespond(404, [
        'ok' => false,
        'error' => [
            'code' => 'BUILD_NOT_FOUND',
            'message' => 'The requested build was not found.',
        ],
    ]);
}

try {
    $record = jenxBuildStateGet($requestId);
} catch (Throwable $exception) {
    // Keep internal paths, exception details and state contents out
    // of the HTTP response. The PHP error log can record diagnostics.
    error_log(
        'Jen-X status API: failed to read build state for ' . $requestId
        . ' (' . get_class($exception) . ')'
    );

    jenxStatusRespond(500, [
        'ok' => false,
        'error' => [
            'code' => 'STATUS_UNAVAILABLE',
            'message' => 'Build status is temporarily unavailable.',
        ],
    ]);
}

$terminalStates = [
    'SUCCEEDED',
    'DISPATCH_REJECTED',
    'BUILD_FAILED',
    'RESULT_REJECTED',
];

$build = [
    'request_id' => $record['request_id'],
    'status' => $record['status'],
    'terminal' => in_array($record['status'], $terminalStates, true),
    'release_tag' => $record['release_tag'],
    'test_only' => $record['test_only'],
    'source_branch' => $record['source_branch'],
    'expected_source_commit' => $record['expected_source_commit'],
    'created_at' => $record['created_at'],
    'updated_at' => $record['updated_at'],
];

// Include verified result details only after successful completion.
if (
    $record['status'] === 'SUCCEEDED'
    && is_array($record['result'] ?? null)
) {
    $result = $record['result'];

    $build['result'] = [
        'image' => $result['image'] ?? null,
        'digest' => $result['digest'] ?? null,
        'workflow_run_id' => $result['workflow_run_id'] ?? null,
    ];
}

jenxStatusRespond(200, [
    'ok' => true,
    'build' => $build,
]);
