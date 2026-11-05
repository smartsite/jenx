<?php

declare(strict_types=1);

/**
 * Jen-X Build Dispatcher — CLI only.
 *
 * Usage:
 * php /var/www/jenx/jenx-build.php <release-tag> <full-source-commit-sha>
 *
 * This script never deploys and never automatically retries a dispatch.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

require_once '/var/www/jenx/jenx-config.php';
require_once '/var/www/jenx/jenx-github.php';
require_once '/var/www/jenx/jenx-build-state.php';


function jenxBuildFail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}


/** Make a GET request and require a valid JSON HTTP 200 response. */
function jenxBuildGetJson(array $config, string $endpoint): array
{
    $response = jenxGithubApiRequest($config, 'GET', $endpoint);

    if ($response['transport_error'] !== null) {
        jenxBuildFail('GitHub API transport error during preflight validation.');
    }

    if ($response['http_status'] !== 200 || !is_array($response['json'])) {
        jenxBuildFail(
            'GitHub API preflight failed with HTTP '
            . $response['http_status'] . '.'
        );
    }

    return $response['json'];
}


/**
 * Resolve a lightweight or annotated Git tag to its commit SHA.
 * Nested annotated tags are allowed, but resolution is bounded.
 */
function jenxBuildResolveTagCommit(array $config, string $tag): string
{
    $repo = $config['GITHUB_REPOSITORY'];

    $ref = jenxBuildGetJson(
        $config,
        '/repos/' . $repo . '/git/ref/tags/' . $tag
    );

    $object = $ref['object'] ?? null;

    if (!is_array($object)) {
        jenxBuildFail('Git tag response has an invalid structure.');
    }

    for ($depth = 0; $depth < 5; $depth++) {
        $type = $object['type'] ?? null;
        $sha = strtolower((string) ($object['sha'] ?? ''));

        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            jenxBuildFail('Git tag contains an invalid object SHA.');
        }

        if ($type === 'commit') {
            return $sha;
        }

        if ($type !== 'tag') {
            jenxBuildFail('Git tag does not ultimately reference a commit.');
        }

        $tagObject = jenxBuildGetJson(
            $config,
            '/repos/' . $repo . '/git/tags/' . $sha
        );

        $object = $tagObject['object'] ?? null;

        if (!is_array($object)) {
            jenxBuildFail('Annotated Git tag response is invalid.');
        }
    }

    jenxBuildFail('Git tag nesting exceeds the allowed depth.');
}


/** Confirm that the commit is on the history of portable. */
function jenxBuildVerifyCommitOnPortable(
    array $config,
    string $commit
): void {
    $repo = $config['GITHUB_REPOSITORY'];

    // Base is the requested commit; head is the portable branch.
    $comparison = jenxBuildGetJson(
        $config,
        '/repos/' . $repo . '/compare/' . $commit . '...portable'
    );

    $status = $comparison['status'] ?? null;

    if (!in_array($status, ['ahead', 'identical'], true)) {
        jenxBuildFail(
            'Requested source commit is not confirmed as an ancestor of portable.'
        );
    }
}


/** Generate an RFC 4122 version 4 UUID. */
function jenxBuildGenerateRequestId(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}


/* ---------- Input validation ---------- */

/* ---------- Input validation ---------- */

$dryRun = ($argc === 4 && $argv[1] === '--dry-run');
$testOnly = ($argc === 4 && $argv[1] === '--test-only');

if ($dryRun || $testOnly) {
    $releaseTag = $argv[2];
    $sourceCommit = strtolower($argv[3]);
} else {
    jenxBuildFail(
        'Dry-run or test-only mode required: '
        . 'use --dry-run <release-tag> <full-source-commit-sha> '
        . 'or --test-only <release-tag> <full-source-commit-sha>'
    );
}

if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $releaseTag)) {
    jenxBuildFail('Release tag format is invalid.');
}

if (!preg_match('/^[0-9a-f]{40}$/', $sourceCommit)) {
    jenxBuildFail('Source commit must be a full 40-character hexadecimal SHA.');
}


/* ---------- Preflight: no state changes or dispatch yet ---------- */

try {
    $config = jenxLoadConfig();

    if (($config['BUILD_BRANCH'] ?? null) !== 'portable') {
        jenxBuildFail('Configured build branch must be portable.');
    }

    $tagCommit = jenxBuildResolveTagCommit($config, $releaseTag);

    if (!hash_equals($tagCommit, $sourceCommit)) {
        jenxBuildFail('Release tag does not point to the supplied source commit.');
    }

    jenxBuildVerifyCommitOnPortable($config, $sourceCommit);

    // Confirm the configured workflow exists and is readable.
    $workflow = jenxBuildGetJson(
        $config,
        '/repos/' . $config['GITHUB_REPOSITORY']
        . '/actions/workflows/' . $config['GITHUB_WORKFLOW_ID']
    );

    if (($workflow['state'] ?? null) !== 'active') {
        jenxBuildFail('Configured GitHub Actions workflow is not active.');
    }

    if ($dryRun) {
        echo "DRY RUN PASSED\n";
        echo "Release tag: {$releaseTag}\n";
        echo "Source commit: {$sourceCommit}\n";
        echo "Branch: portable\n";
        echo "Workflow: active\n";
        echo "State changes: none\n";
        echo "Workflow dispatch: none\n";
        exit(0);
    }

    if ($testOnly) {
        echo "TEST-ONLY PREFLIGHT PASSED\n";
        echo "Release tag: {$releaseTag}\n";
        echo "Source commit: {$sourceCommit}\n";
        echo "Branch: portable\n";
        echo "Workflow: active\n";
        echo "Image publication: unique test tag only\n";
    }

    /* ---------- Persist request before dispatch ---------- */

    $requestId = jenxBuildGenerateRequestId();

    jenxBuildStateCreate(
        $requestId,
        $releaseTag,
        $sourceCommit,
        $testOnly
    );

    // This durable transition MUST happen before the POST.
    jenxBuildStateTransition($requestId, 'DISPATCHING');

    /*
     * Exactly one dispatch attempt. Do not wrap this call in an automatic
     * retry. A transport failure can mean GitHub accepted the request.
     */
    $dispatchResponse = jenxGithubDispatchWorkflow(
        $config,
        $releaseTag,
        $requestId,
        $sourceCommit,
        $testOnly
    );

    $dispatchDetails = [
        'attempt_started_at' => jenxBuildStateGet($requestId)['dispatch']['attempt_started_at']
            ?? null,
        'outcome' => 'unknown',
        'http_status' => $dispatchResponse['http_status'],
        'response_at' => gmdate('c'),
    ];

    if ($dispatchResponse['transport_error'] !== null) {
        $dispatchDetails['outcome'] = 'unknown';
        $dispatchDetails['transport_errno'] =
            $dispatchResponse['transport_errno'];

        jenxBuildStateTransition(
            $requestId,
            'RECONCILIATION_REQUIRED',
            [
                'dispatch' => $dispatchDetails,
                'error' => [
                    'code' => 'DISPATCH_TRANSPORT_ERROR',
                    'message' => 'Dispatch outcome is unknown; reconcile before proceeding.',
                ],
            ]
        );

        echo "Request ID: {$requestId}\n";
        echo "Status: RECONCILIATION_REQUIRED\n";
        exit(2);
    }

    if ($dispatchResponse['http_status'] === 204) {
        $dispatchDetails['outcome'] = 'accepted';

        jenxBuildStateTransition(
            $requestId,
            'DISPATCHED',
            ['dispatch' => $dispatchDetails]
        );

        echo "Request ID: {$requestId}\n";
        echo "Status: DISPATCHED\n";
        echo "GitHub accepted the dispatch request; build completion is not yet confirmed.\n";
        exit(0);
    }

    if (
        $dispatchResponse['http_status'] >= 400 &&
        $dispatchResponse['http_status'] < 500 &&
        $dispatchResponse['http_status'] !== 408
    ) {
        $dispatchDetails['outcome'] = 'rejected';

        jenxBuildStateTransition(
            $requestId,
            'DISPATCH_REJECTED',
            [
                'dispatch' => $dispatchDetails,
                'error' => [
                    'code' => 'DISPATCH_REJECTED',
                    'message' => 'GitHub rejected the dispatch request.',
                ],
            ]
        );

        echo "Request ID: {$requestId}\n";
        echo "Status: DISPATCH_REJECTED\n";
        echo "HTTP status: {$dispatchResponse['http_status']}\n";
        exit(1);
    }

    // Includes unexpected 2xx, 3xx and 5xx responses.
    jenxBuildStateTransition(
        $requestId,
        'RECONCILIATION_REQUIRED',
        [
            'dispatch' => $dispatchDetails,
            'error' => [
                'code' => 'DISPATCH_UNEXPECTED_RESPONSE',
                'message' => 'Unexpected dispatch response; reconcile before proceeding.',
            ],
        ]
    );

    echo "Request ID: {$requestId}\n";
    echo "Status: RECONCILIATION_REQUIRED\n";
    echo "HTTP status: {$dispatchResponse['http_status']}\n";
    exit(2);

} catch (Throwable $exception) {
    // Avoid printing response bodies, tokens or configuration.
    jenxBuildFail(
        'Build preparation or state update failed: '
        . get_class($exception) . ': ' . $exception->getMessage()
    );
}
