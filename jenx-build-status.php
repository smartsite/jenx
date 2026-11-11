<?php

declare(strict_types=1);

/**
 * Jen-X Build Status CLI
 *
 * Read-only: returns safe dashboard metadata for one build record.
 *
 * No network requests.
 * No state changes.
 */

require_once __DIR__ . '/jenx-build-state.php';

function jenxBuildStatusRespond(int $status, array $payload): never
{
    if ($status !== 200) {
        fwrite(
            STDERR,
            ($payload['error']['message'] ?? 'Build status failed') . PHP_EOL
        );
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    exit($status === 200 ? 0 : 1);
}

try {
    $requestId = $argv[1] ?? '';

    if ($requestId === '' || isset($argv[2])) {
        jenxBuildStatusRespond(400, [
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
        jenxBuildStatusRespond(400, [
            'ok' => false,
            'error' => [
                'code' => 'INVALID_REQUEST_ID',
                'message' => 'A valid request_id is required.',
            ],
        ]);
    }

    $recordPath = jenxBuildStateRecordPath($requestId);

    if (is_link($recordPath) || !is_file($recordPath)) {
        jenxBuildStatusRespond(404, [
            'ok' => false,
            'error' => [
                'code' => 'BUILD_NOT_FOUND',
                'message' => 'The requested build was not found.',
            ],
        ]);
    }

    $record = jenxBuildStateGet($requestId);

    $build = [
        'request_id' => $record['request_id'],
        'status' => $record['status'],
        'terminal' => in_array(
            $record['status'],
            [
                'SUCCEEDED',
                'DISPATCH_REJECTED',
                'BUILD_FAILED',
                'RESULT_REJECTED',
            ],
            true
        ),
        'release_tag' => $record['release_tag'],
        'test_only' => $record['test_only'],
        'source_branch' => $record['source_branch'],
        'expected_source_commit' => $record['expected_source_commit'],
        'created_at' => $record['created_at'],
        'updated_at' => $record['updated_at'],
    ];

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

    jenxBuildStatusRespond(200, [
        'ok' => true,
        'build' => $build,
    ]);

} catch (Throwable $exception) {
    error_log(
        'Jen-X build status CLI: failed to read build record ('
        . get_class($exception) . ')'
    );

    jenxBuildStatusRespond(500, [
        'ok' => false,
        'error' => [
            'code' => 'BUILD_STATUS_UNAVAILABLE',
            'message' => 'Build status is temporarily unavailable.',
        ],
    ]);
}
