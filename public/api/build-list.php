<?php

declare(strict_types=1);

/**
 * Jen-X Build List API
 *
 * Read-only: scans existing state records and returns safe
 * dashboard metadata. Never contacts GitHub or changes state.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function jenxBuildListRespond(int $status, array $payload): never
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');

    jenxBuildListRespond(405, [
        'ok' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => 'Only GET requests are supported.',
        ],
    ]);
}

require_once dirname(__DIR__, 2) . '/jenx-build-state.php';

try {
    // Derive the state directory from the existing state-path helper.
    // This does not create or modify a state record.
    $sampleId = '00000000-0000-4000-8000-000000000000';
    $stateDirectory = dirname(jenxBuildStateRecordPath($sampleId));

    $files = glob($stateDirectory . '/*.json');

    if ($files === false) {
        throw new RuntimeException('Unable to scan build state directory.');
    }

    $builds = [];

    foreach ($files as $file) {
        if (is_link($file) || !is_file($file)) {
            continue;
        }

        $requestId = basename($file, '.json');

        try {
            $requestId = jenxBuildStateValidateRequestId($requestId);
            $record = jenxBuildStateGet($requestId);
        } catch (Throwable $exception) {
            // Ignore invalid or unreadable individual records.
            continue;
        }

        if (!is_array($record)) {
            continue;
        }

        $build = [
            'request_id' => $record['request_id'],
            'status' => $record['status'],
            'release_tag' => $record['release_tag'],
            'test_only' => $record['test_only'],
            'source_branch' => $record['source_branch'],
            'expected_source_commit' => $record['expected_source_commit'],
            'created_at' => $record['created_at'],
            'updated_at' => $record['updated_at'],
        ];

        if (
            ($record['status'] ?? null) === 'SUCCEEDED'
            && is_array($record['result'] ?? null)
        ) {
            $result = $record['result'];

            $build['result'] = [
                'image' => $result['image'] ?? null,
                'digest' => $result['digest'] ?? null,
                'workflow_run_id' => $result['workflow_run_id'] ?? null,
            ];
        }

        $builds[] = $build;
    }

    usort(
        $builds,
        static fn(array $a, array $b): int =>
            strcmp(
                (string) ($b['updated_at'] ?? ''),
                (string) ($a['updated_at'] ?? '')
            )
    );

    // Keep the initial dashboard response bounded.
    $builds = array_slice($builds, 0, 50);

    jenxBuildListRespond(200, [
        'ok' => true,
        'count' => count($builds),
        'builds' => $builds,
    ]);
} catch (Throwable $exception) {
    error_log(
        'Jen-X build list API: failed to list build records ('
        . get_class($exception) . ')'
    );

    jenxBuildListRespond(500, [
        'ok' => false,
        'error' => [
            'code' => 'BUILD_LIST_UNAVAILABLE',
            'message' => 'Build list is temporarily unavailable.',
        ],
    ]);
}
