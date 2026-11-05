<?php

declare(strict_types=1);

/**
 * Jen-X Build Collector — CLI only.
 *
 * Usage:
 *   php /var/www/jenx/jenx-collect.php <request-uuid>
 *
 * This script never dispatches or retries a workflow.
 * It reconciles a persisted request with GitHub and verifies its result.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once '/var/www/jenx/jenx-config.php';
require_once '/var/www/jenx/jenx-github.php';
require_once '/var/www/jenx/jenx-build-state.php';


/**
 * A correlated, completed workflow produced an artifact that definitively
 * violates the build-result contract. This is distinct from run/API ambiguity.
 */
final class JenxCollectResultRejected extends UnexpectedValueException
{
}


function jenxCollectStop(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($exitCode);
}


function jenxCollectRespond(array $record, string $message, int $exitCode = 0): never
{
    echo "Request ID: {$record['request_id']}\n";
    echo "Status: {$record['status']}\n";
    echo $message . "\n";
    exit($exitCode);
}


/** Require a successful GitHub JSON response. */
function jenxCollectRequireJson(array $response, string $operation): array
{
    if (($response['transport_error'] ?? null) !== null) {
        throw new RuntimeException(
            "GitHub transport error during {$operation}; retry collection later."
        );
    }

    if (
        ($response['http_status'] ?? 0) !== 200 ||
        !is_array($response['json'] ?? null)
    ) {
        throw new RuntimeException(
            "GitHub API did not return valid JSON HTTP 200 during {$operation}."
        );
    }

    return $response['json'];
}


/** Persist only the run metadata needed for later reconciliation. */
function jenxCollectRunMetadata(array $run): array
{
    return [
        'run_id' => (string) ($run['id'] ?? ''),
        'workflow_id' => (string) ($run['workflow_id'] ?? ''),
        'status' => (string) ($run['status'] ?? ''),
        'conclusion' => $run['conclusion'] ?? null,
        'event' => (string) ($run['event'] ?? ''),
        'head_branch' => (string) ($run['head_branch'] ?? ''),
        'head_sha' => strtolower((string) ($run['head_sha'] ?? '')),
        'display_title' => (string) ($run['display_title'] ?? ''),
        'html_url' => (string) ($run['html_url'] ?? ''),
        'observed_at' => gmdate('c'),
    ];
}


/**
 * Validate run identity independently of its current status.
 * The UUID is the primary correlation key; all other identity fields
 * must also agree with the persisted request and configured workflow.
 */

function jenxCollectValidateRun(
    array $run,
    array $record,
    array $config
): void {
    $runId = $run['id'] ?? null;
    $workflowId = $run['workflow_id'] ?? null;
    $repository = $run['repository']['full_name'] ?? null;
    $title = $run['display_title'] ?? null;
    $headSha = strtolower((string) ($run['head_sha'] ?? ''));

    $expectedTitle = sprintf(
        'Jen-X build %s (%s)%s',
        $record['request_id'],
        $record['release_tag'],
        $record['test_only'] ? ' [TEST ONLY]' : ''
    );

    if (
        !is_int($runId) ||
        $runId < 1 ||
        (string) $workflowId !== (string) $config['GITHUB_WORKFLOW_ID'] ||
        !is_string($repository) ||
        strcasecmp($repository, $config['GITHUB_REPOSITORY']) !== 0 ||
        ($run['event'] ?? null) !== 'workflow_dispatch' ||
        ($run['head_branch'] ?? null) !== 'portable' ||
        !preg_match('/^[0-9a-f]{40}$/', $headSha) ||
        !is_string($title) ||
        $title !== $expectedTitle
    ) {
        throw new UnexpectedValueException(
            'Workflow run identity does not match the saved build request.'
        );
    }

    /*
     * Deliberately do not compare head_sha with expected_source_commit.
     * The workflow dispatch context is portable, but the workflow itself
     * explicitly checks out the commit resolved from the requested tag.
     * The artifact's source_commit is checked against the saved commit.
     */
}

/** Locate exactly one workflow run matching this request. */
function jenxCollectFindRun(
    array $config,
    array $record
): ?array {
    $response = jenxGithubListWorkflowRuns($config);
    $data = jenxCollectRequireJson($response, 'workflow-run listing');

    $runs = $data['workflow_runs'] ?? null;

    if (!is_array($runs)) {
        throw new UnexpectedValueException(
            'GitHub workflow-run listing has an invalid structure.'
        );
    }

    $matches = [];

    foreach ($runs as $run) {
        if (!is_array($run)) {
            continue;
        }

        $title = $run['display_title'] ?? '';

        if (
            is_string($title) &&
            str_contains($title, $record['request_id'])
        ) {
            $matches[] = $run;
        }
    }

    if (count($matches) > 1) {
        throw new UnexpectedValueException(
            'Multiple workflow runs match this request UUID.'
        );
    }

    if ($matches === []) {
        return null;
    }

    jenxCollectValidateRun($matches[0], $record, $config);

    return $matches[0];
}


/** Fetch a previously correlated run by its saved GitHub run ID. */
function jenxCollectGetSavedRun(
    array $config,
    array $record
): ?array {
    $saved = $record['workflow'] ?? null;

    if (!is_array($saved) || !ctype_digit((string) ($saved['run_id'] ?? ''))) {
        return null;
    }

    $runId = (int) $saved['run_id'];

    if ($runId < 1) {
        throw new UnexpectedValueException('Saved workflow run ID is invalid.');
    }

    $response = jenxGithubGetWorkflowRun($config, $runId);
    $run = jenxCollectRequireJson($response, 'workflow-run lookup');

    jenxCollectValidateRun($run, $record, $config);

    return $run;
}


/** Apply a run's observed status through the permitted state transitions. */


function jenxCollectRecordRun(
    array $record,
    array $run
): array {
    $requestId = $record['request_id'];
    $current = $record['status'];
    $workflow = jenxCollectRunMetadata($run);
    $runStatus = $run['status'] ?? null;
    $conclusion = $run['conclusion'] ?? null;

    if (!in_array($runStatus, ['queued', 'in_progress', 'completed'], true)) {
        throw new UnexpectedValueException(
            'GitHub returned an unrecognised workflow status.'
        );
    }

    /*
     * Finding a run with the exact request UUID proves that a dispatch
     * occurred. Record that fact before attempting a later transition.
     */
    if ($current === 'DISPATCHING') {
        $record = jenxBuildStateTransition(
            $requestId,
            'DISPATCHED',
            ['workflow' => $workflow]
        );
        $current = 'DISPATCHED';
    }

    if ($runStatus === 'queued') {
        if ($current === 'RECONCILIATION_REQUIRED') {
            return jenxBuildStateTransition(
                $requestId,
                'DISPATCHED',
                ['workflow' => $workflow]
            );
        }

        if ($current === 'DISPATCHED') {
            // Same-state transitions are forbidden by the state helper.
            return $record;
        }

        if (in_array($current, ['RUNNING', 'VERIFYING'], true)) {
            throw new UnexpectedValueException(
                'Workflow status regressed after progress was recorded.'
            );
        }

        return $record;
    }

    if ($runStatus === 'in_progress') {
        if (in_array($current, ['DISPATCHED', 'RECONCILIATION_REQUIRED'], true)) {
            return jenxBuildStateTransition(
                $requestId,
                'RUNNING',
                ['workflow' => $workflow]
            );
        }

        if ($current === 'RUNNING') {
            // Same-state transitions are forbidden by the state helper.
            return $record;
        }

        if ($current === 'VERIFYING') {
            throw new UnexpectedValueException(
                'Workflow returned to in_progress after verification began.'
            );
        }

        return $record;
    }

    // The run is completed.
    if ($conclusion === 'success') {
        if (in_array($current, ['DISPATCHED', 'RUNNING', 'RECONCILIATION_REQUIRED'], true)) {
            return jenxBuildStateTransition(
                $requestId,
                'VERIFYING',
                ['workflow' => $workflow]
            );
        }

        if ($current === 'VERIFYING') {
            // Same-state transitions are forbidden by the state helper.
            return $record;
        }

        throw new UnexpectedValueException(
            'Successful workflow conflicts with the persisted build state.'
        );
    }

    if (in_array(
        $current,
        ['DISPATCHED', 'RUNNING', 'RECONCILIATION_REQUIRED'],
        true
    )) {
        return jenxBuildStateTransition(
            $requestId,
            'BUILD_FAILED',
            [
                'workflow' => $workflow,
                'error' => [
                    'code' => 'WORKFLOW_FAILED',
                    'message' => 'GitHub workflow completed without success.',
                    'conclusion' => is_string($conclusion) ? $conclusion : null,
                    'detected_at' => gmdate('c'),
                ],
            ]
        );
    }

    throw new UnexpectedValueException(
        'Workflow failure conflicts with the persisted build state.'
    );
}


/**
 * Download and extract exactly one build-result.json entry.
 * No other ZIP entries are accepted.
 */
function jenxCollectReadArtifactJson(
    array $config,
    int $artifactId
): array {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException(
            'PHP ZIP extension is required to verify build artifacts.'
        );
    }

    $directory = sys_get_temp_dir() . '/jenx-collect-' . bin2hex(random_bytes(8));

    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Could not create artifact working directory.');
    }

    $zipPath = $directory . '/artifact.zip';

    try {
        $download = jenxGithubDownloadArtifactZip(
            $config,
            $artifactId,
            $zipPath
        );

        if (($download['ok'] ?? false) !== true) {
            throw new RuntimeException(
                'Artifact download failed; collect again after checking GitHub.'
            );
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new UnexpectedValueException('Downloaded artifact is not a valid ZIP.');
        }

        try {
            if ($zip->numFiles !== 1 || $zip->getNameIndex(0) !== 'build-result.json') {
                throw new UnexpectedValueException(
                    'Artifact ZIP must contain only build-result.json.'
                );
            }

            $stat = $zip->statIndex(0);

            if (
                !is_array($stat) ||
                !isset($stat['size']) ||
                $stat['size'] < 1 ||
                $stat['size'] > 1024 * 1024
            ) {
                throw new UnexpectedValueException(
                    'Artifact result file has an invalid size.'
                );
            }

            $stream = $zip->getStream('build-result.json');

            if ($stream === false) {
                throw new UnexpectedValueException(
                    'Could not read build-result.json from artifact.'
                );
            }

            try {
                $contents = stream_get_contents($stream, 1024 * 1024 + 1);
            } finally {
                fclose($stream);
            }

            if (!is_string($contents) || strlen($contents) > 1024 * 1024) {
                throw new UnexpectedValueException(
                    'Artifact result file exceeds the allowed size.'
                );
            }

            try {
                $result = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new UnexpectedValueException(
                    'Artifact result is not valid JSON.',
                    0,
                    $exception
                );
            }

            if (!is_array($result)) {
                throw new UnexpectedValueException(
                    'Artifact result JSON has an invalid structure.'
                );
            }

            return $result;
        } finally {
            $zip->close();
        }
    } finally {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        @rmdir($directory);
    }
}


/** Validate the complete schema-version-2 result contract. */
function jenxCollectValidateResult(
    array $result,
    array $record,
    array $config,
    int $runId
): void {
    if (
        ($result['schema_version'] ?? null) !== 2 ||
        ($result['request_id'] ?? null) !== $record['request_id'] ||
        ($result['release_tag'] ?? null) !== $record['release_tag'] ||
        strtolower((string) ($result['source_commit'] ?? ''))
            !== $record['expected_source_commit'] ||
        ($result['image'] ?? null) !== $config['IMAGE_NAME'] ||
        !is_string($result['digest'] ?? null) ||
        !preg_match('/^sha256:[0-9a-f]{64}$/', $result['digest']) ||
        !is_string($result['workflow_run_id'] ?? null) ||
        !ctype_digit($result['workflow_run_id']) ||
        (int) $result['workflow_run_id'] !== $runId ||
        ($result['status'] ?? null) !== 'success' ||
        !is_bool($result['test_only'] ?? null) ||
        $result['test_only'] !== $record['test_only']
    ) {
        throw new UnexpectedValueException(
            'Artifact result does not match the schema-version-2 request contract.'
        );
    }
}


/* ----------------------------- Main ---------------------------------- */

if ($argc !== 2) {
    jenxCollectStop(
        'Usage: php /var/www/jenx/jenx-collect.php <request-uuid>'
    );
}

try {
    $requestId = jenxBuildStateValidateRequestId($argv[1]);
    $config = jenxLoadConfig();

    // Recover an abandoned dispatch only after the configured grace period.
    $record = jenxBuildStateRecover($requestId, 120);
    $record = jenxBuildStateGet($requestId);

    if (in_array(
        $record['status'],
        ['SUCCEEDED', 'DISPATCH_REJECTED', 'BUILD_FAILED', 'RESULT_REJECTED'],
        true
    )) {
        jenxCollectRespond($record, 'Request is already in a terminal state.');
    }

    if ($record['status'] === 'PREPARED') {
        jenxCollectRespond(
            $record,
            'Request has not been dispatched. Collect will not dispatch it.'
        );
    }

    if ($record['status'] === 'DISPATCHING') {
        jenxCollectRespond(
            $record,
            'Dispatch is still within its grace period. Collect again later.',
            2
        );
    }

    // Prefer a previously correlated run ID; otherwise reconcile by UUID.
    $run = jenxCollectGetSavedRun($config, $record);

    if ($run === null) {
        $run = jenxCollectFindRun($config, $record);
    }

    if ($run === null) {
        jenxCollectRespond(
            $record,
            'No matching workflow run is visible yet. No dispatch was attempted.',
            2
        );
    }

    $record = jenxBuildStateGet($requestId);
    $record = jenxCollectRecordRun($record, $run);

    if (in_array(
        $record['status'],
        ['BUILD_FAILED', 'DISPATCH_REJECTED', 'RESULT_REJECTED', 'SUCCEEDED'],
        true
    )) {
        jenxCollectRespond($record, 'Workflow state reconciled.');
    }

    if ($record['status'] !== 'VERIFYING') {
        jenxCollectRespond(
            $record,
            'Workflow is not yet ready for artifact verification.',
            2
        );
    }

    $runId = (int) $run['id'];

    $artifactResponse = jenxGithubListRunArtifacts($config, $runId);
    $artifactData = jenxCollectRequireJson($artifactResponse, 'artifact listing');
    $artifacts = $artifactData['artifacts'] ?? null;

    if (!is_array($artifacts)) {
        throw new UnexpectedValueException(
            'GitHub artifact listing has an invalid structure.'
        );
    }

    $expectedArtifactName = $config['BUILD_ARTIFACT_PREFIX'] . $runId;
    $matches = [];

    foreach ($artifacts as $artifact) {
        if (
            is_array($artifact) &&
            ($artifact['name'] ?? null) === $expectedArtifactName
        ) {
            $matches[] = $artifact;
        }
    }

    if ($matches === []) {
        jenxCollectRespond(
            $record,
            'The workflow succeeded, but its expected artifact is not available yet. Collect again later.',
            2
        );
    }

    if (count($matches) !== 1) {
        throw new UnexpectedValueException(
            'Multiple artifacts match the expected build-result name.'
        );
    }

    $artifact = $matches[0];

    if (!is_int($artifact['id'] ?? null) || $artifact['id'] < 1) {
        throw new UnexpectedValueException(
            'Expected artifact has an invalid identifier.'
        );
    }

    if (($artifact['expired'] ?? null) !== false) {
        jenxCollectRespond(
            $record,
            'The expected artifact is expired or its availability cannot be confirmed. '
            . 'The request remains in VERIFYING; no build was dispatched.',
            2
        );
    }

    try {
        $result = jenxCollectReadArtifactJson($config, $artifact['id']);
        jenxCollectValidateResult($result, $record, $config, $runId);
    } catch (UnexpectedValueException $exception) {
        /*
         * At this point the workflow run has already been correlated and
         * verified. A malformed ZIP/JSON/result contract is therefore a
         * definite result rejection, unlike an ambiguous workflow run.
         */
        throw new JenxCollectResultRejected(
            $exception->getMessage(),
            0,
            $exception
        );
    }

    // The state helper performs its own success-gate validation as well.
    $record = jenxBuildStateTransition(
        $requestId,
        'SUCCEEDED',
        ['result' => $result]
    );

    jenxCollectRespond(
        $record,
        'Workflow and schema-version-2 artifact verified successfully.'
    );
} catch (JenxCollectResultRejected $exception) {
    /*
     * Only a definitively invalid artifact from an already correlated,
     * completed workflow may move VERIFYING to RESULT_REJECTED.
     */
    try {
        if (isset($requestId)) {
            $record = jenxBuildStateGet($requestId);

            if ($record['status'] === 'VERIFYING') {
                $record = jenxBuildStateTransition(
                    $requestId,
                    'RESULT_REJECTED',
                    [
                        'error' => [
                            'code' => 'RESULT_VALIDATION_FAILED',
                            'message' => $exception->getMessage(),
                            'detected_at' => gmdate('c'),
                        ],
                    ]
                );
            }

            fwrite(STDERR, "Request ID: {$requestId}\n");
            fwrite(STDERR, "Status: {$record['status']}\n");
        }
    } catch (Throwable) {
        // Preserve the original artifact validation error.
    }

    jenxCollectStop($exception->getMessage(), 2);
} catch (UnexpectedValueException $exception) {
    /*
     * Run identity mismatches, ambiguous matches, and state inconsistencies
     * are not proof that an artifact is bad. Reconcile where possible, but
     * never reject a result merely because run correlation failed.
     */
    try {
        if (isset($requestId)) {
            $record = jenxBuildStateGet($requestId);

            if (in_array($record['status'], ['DISPATCHED', 'RUNNING'], true)) {
                $record = jenxBuildStateTransition(
                    $requestId,
                    'RECONCILIATION_REQUIRED',
                    [
                        'error' => [
                            'code' => 'RUN_RECONCILIATION_FAILED',
                            'message' => $exception->getMessage(),
                            'detected_at' => gmdate('c'),
                        ],
                    ]
                );
            }

            fwrite(STDERR, "Request ID: {$requestId}\n");
            fwrite(STDERR, "Status: {$record['status']}\n");
        }
    } catch (Throwable) {
        // Preserve the original validation error; do not conceal it.
    }

    jenxCollectStop($exception->getMessage(), 2);
} catch (Throwable $exception) {
    // Network/API failures are not evidence of a failed build or bad artifact.
    jenxCollectStop(
        'Collection could not be completed: '
        . get_class($exception) . ': ' . $exception->getMessage(),
        2
    );
}
