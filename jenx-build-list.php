<?php

declare(strict_types=1);

/**
 * Jen-X Build List CLI
 *
 * Read-only: scans existing build state records and emits
 * safe dashboard metadata as JSON.
 *
 * No network requests.
 * No state changes.
 */

require_once __DIR__ . '/jenx-build-state.php';

function jenxBuildListRespond(int $status, array $payload): never
{
    if ($status !== 200) {
        // Keep diagnostics off stdout; stdout is the API response.
        fwrite(STDERR, ($payload['error']['message'] ?? 'Build list failed') . PHP_EOL);
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    exit($status === 200 ? 0 : 1);
}

try {
    $stateDirectory = JENX_BUILD_STATE_DIRECTORY;

    if (is_link($stateDirectory) || !is_dir($stateDirectory)) {
        throw new RuntimeException(
            'Build state directory does not exist or is unsafe.'
        );
    }

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

    $builds = array_slice($builds, 0, 50);

    jenxBuildListRespond(200, [
        'ok' => true,
        'count' => count($builds),
        'builds' => $builds,
    ]);

} catch (Throwable $exception) {
    error_log(
        'Jen-X build list CLI: failed to list build records ('
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
