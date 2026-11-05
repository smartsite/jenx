<?php
declare(strict_types=1);

/**
 * Disposable, offline regression harness for jenx-collect.php.
 *
 * No GitHub access, workflow dispatches, or live state-file changes.
 * State transitions are tested using an in-memory test double.
 *
 * Usage:
 *   php /var/www/jenx/jenx-collect-test.php
 *   php /var/www/jenx/jenx-collect-test.php /var/www/jenx/jenx-collect.php
 */

$collectorPath = $argv[1] ?? '/var/www/jenx/jenx-collect.php';

if (!is_file($collectorPath) || !is_readable($collectorPath)) {
    fwrite(STDERR, "FAIL: collector file not readable: {$collectorPath}\n");
    exit(2);
}

$source = file_get_contents($collectorPath);

if (!is_string($source)) {
    fwrite(STDERR, "FAIL: unable to read collector source.\n");
    exit(2);
}

$mainMarker = '/* ----------------------------- Main ---------------------------------- */';
$mainPosition = strpos($source, $mainMarker);

if ($mainPosition === false) {
    fwrite(STDERR, "FAIL: could not locate collector main marker.\n");
    exit(2);
}

$definitions = substr($source, 0, $mainPosition);
$definitions = preg_replace('/^<\?php\s*/', '', $definitions, 1);
$definitions = preg_replace('/^require_once\s+[^;]+;\s*$/m', '', $definitions);

if (!is_string($definitions) || trim($definitions) === '') {
    fwrite(STDERR, "FAIL: unable to prepare collector function definitions.\n");
    exit(2);
}

$tests = 0;
$failures = 0;
$memoryStates = [];

function testAssert(bool $condition, string $message): void
{
    global $tests, $failures;

    $tests++;

    if ($condition) {
        echo "PASS: {$message}\n";
    } else {
        $failures++;
        echo "FAIL: {$message}\n";
    }
}

function testStateTransition(
    string $requestId,
    string $newStatus,
    array $updates = []
): array {
    global $memoryStates;

    if (!isset($memoryStates[$requestId])) {
        throw new RuntimeException('Test double: unknown request UUID.');
    }

    $current = $memoryStates[$requestId]['status'];

    if ($current === $newStatus) {
        throw new LogicException(
            "Test double forbids same-state transition: {$current}"
        );
    }

    $allowed = [
        'PREPARED' => ['DISPATCHING'],
        'DISPATCHING' => [
            'DISPATCHED',
            'DISPATCH_REJECTED',
            'RECONCILIATION_REQUIRED',
        ],
        'DISPATCHED' => [
            'RUNNING',
            'VERIFYING',
            'BUILD_FAILED',
            'RECONCILIATION_REQUIRED',
        ],
        'RUNNING' => [
            'VERIFYING',
            'BUILD_FAILED',
            'RECONCILIATION_REQUIRED',
        ],
        'VERIFYING' => [
            'SUCCEEDED',
            'RESULT_REJECTED',
            'RECONCILIATION_REQUIRED',
        ],
        'RECONCILIATION_REQUIRED' => [
            'DISPATCHED',
            'RUNNING',
            'VERIFYING',
            'DISPATCH_REJECTED',
            'BUILD_FAILED',
        ],
    ];

    if (!in_array($newStatus, $allowed[$current] ?? [], true)) {
        throw new LogicException(
            "Test double forbids transition: {$current} -> {$newStatus}"
        );
    }

    $memoryStates[$requestId] = array_merge(
        $memoryStates[$requestId],
        $updates,
        ['status' => $newStatus]
    );

    return $memoryStates[$requestId];
}

// Stub only the state functions used by the collector's function definitions.
function jenxBuildStateTransition(
    string $requestId,
    string $newStatus,
    array $updates = []
): array {
    return testStateTransition($requestId, $newStatus, $updates);
}

function jenxBuildStateValidateRequestId(string $requestId): string
{
    if (!preg_match(
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
        $requestId
    )) {
        throw new InvalidArgumentException('Request ID must be a UUID.');
    }

    return strtolower($requestId);
}

// Load collector function definitions without its live includes or main execution.
eval($definitions);

$requestId = '11111111-2222-4333-8444-555555555555';

$recordBase = [
    'request_id' => $requestId,
    'release_tag' => 'v9.9.9-test',
    'expected_source_commit' => str_repeat('a', 40),
    'test_only' => true,
    'status' => 'DISPATCHED',
    'workflow' => null,
    'result' => null,
    'error' => null,
];

$config = [
    'GITHUB_WORKFLOW_ID' => '399052069',
    'GITHUB_REPOSITORY' => 'HUMNN-INNOVATIONS/foundd',
    'IMAGE_NAME' => 'ghcr.io/humnn-innovations/foundd',
    'BUILD_ARTIFACT_PREFIX' => 'jenx-build-result-',
];

$runBase = [
    'id' => 123456789,
    'workflow_id' => 399052069,
    'repository' => ['full_name' => 'HUMNN-INNOVATIONS/foundd'],
    'display_title' =>
        "Jen-X build {$requestId} (v9.9.9-test) [TEST ONLY]",
    'event' => 'workflow_dispatch',
    'head_branch' => 'portable',
    // Dispatch ref SHA may differ from the tag's source commit.
    'head_sha' => str_repeat('b', 40),
    'status' => 'queued',
    'conclusion' => null,
    'html_url' => 'https://example.invalid/run/123456789',
];

// 1. UUID validation.
try {
    testAssert(
        jenxBuildStateValidateRequestId($requestId) === $requestId,
        'valid request UUID accepted'
    );
} catch (Throwable $e) {
    testAssert(
        false,
        'valid request UUID accepted (' . $e->getMessage() . ')'
    );
}

try {
    jenxBuildStateValidateRequestId('not-a-uuid');
    testAssert(false, 'invalid request UUID rejected');
} catch (InvalidArgumentException) {
    testAssert(true, 'invalid request UUID rejected');
}

// 2. The collector must never dispatch and must guard PREPARED requests.
testAssert(
    !str_contains($source, 'jenxGithubDispatchWorkflow('),
    'collector source contains no workflow-dispatch call'
);

testAssert(
    str_contains($source, "if (\$record['status'] === 'PREPARED')"),
    'PREPARED request has a dedicated early-return guard'
);

testAssert(
    str_contains($source, 'jenxBuildStateRecover($requestId, 120)'),
    'collector invokes stale-dispatch recovery with 120-second grace'
);

// 3. Repeated polling must not attempt same-state transitions.
$cases = [
    [
        'DISPATCHED',
        'queued',
        null,
        'queued run leaves DISPATCHED state unchanged',
    ],
    [
        'RUNNING',
        'in_progress',
        null,
        'in-progress run leaves RUNNING state unchanged',
    ],
    [
        'VERIFYING',
        'completed',
        'success',
        'successful completed run leaves VERIFYING state unchanged',
    ],
];

foreach ($cases as [$status, $runStatus, $conclusion, $label]) {
    $memoryStates[$requestId] = array_merge(
        $recordBase,
        ['status' => $status]
    );

    $run = array_merge(
        $runBase,
        [
            'status' => $runStatus,
            'conclusion' => $conclusion,
        ]
    );

    try {
        $updated = jenxCollectRecordRun(
            $memoryStates[$requestId],
            $run
        );

        testAssert($updated['status'] === $status, $label);
    } catch (Throwable $e) {
        testAssert(
            false,
            $label . ' (' . get_class($e) . ': ' . $e->getMessage() . ')'
        );
    }
}

// 4. Correlated run identity must validate; mismatches must remain run errors.
try {
    jenxCollectValidateRun($runBase, $recordBase, $config);
    testAssert(true, 'valid workflow run identity accepted');
} catch (Throwable $e) {
    testAssert(
        false,
        'valid workflow run identity accepted (' . $e->getMessage() . ')'
    );
}

try {
    $badRun = $runBase;
    $badRun['display_title'] = 'unrelated workflow';

    jenxCollectValidateRun($badRun, $recordBase, $config);
    testAssert(false, 'mismatched workflow run identity rejected');
} catch (UnexpectedValueException $e) {
    testAssert(
        !$e instanceof JenxCollectResultRejected,
        'mismatched workflow run identity rejected as reconciliation error, not artifact rejection'
    );
}

// 5. Validate a schema-v2 artifact and reject a mismatched test_only flag.
$result = [
    'schema_version' => 2,
    'request_id' => $requestId,
    'release_tag' => 'v9.9.9-test',
    'source_commit' => str_repeat('a', 40),
    'image' => $config['IMAGE_NAME'],
    'digest' => 'sha256:' . str_repeat('c', 64),
    'workflow_run_id' => '123456789',
    'test_only' => true,
    'status' => 'success',
];

try {
    jenxCollectValidateResult(
        $result,
        $recordBase,
        $config,
        123456789
    );

    testAssert(true, 'valid schema-v2 artifact contract accepted');
} catch (Throwable $e) {
    testAssert(
        false,
        'valid schema-v2 artifact contract accepted (' . $e->getMessage() . ')'
    );
}

try {
    $badResult = $result;
    $badResult['test_only'] = false;

    jenxCollectValidateResult(
        $badResult,
        $recordBase,
        $config,
        123456789
    );

    testAssert(false, 'artifact with mismatched test_only flag rejected');
} catch (UnexpectedValueException) {
    testAssert(
        true,
        'artifact with mismatched test_only flag rejected'
    );
}

// 6. Invalid artifacts must raise validation errors distinguishable from run errors.
try {
    jenxCollectValidateResult([], $recordBase, $config, 123456789);
    testAssert(false, 'invalid artifact raises validation exception');
} catch (UnexpectedValueException) {
    testAssert(true, 'invalid artifact raises validation exception');
}

testAssert(
    is_subclass_of(
        JenxCollectResultRejected::class,
        UnexpectedValueException::class
    ),
    'dedicated artifact-rejection exception exists'
);

// 7. Stale dispatch recovery is wired into the collector.
// Filesystem timestamp semantics are not exercised by this offline harness.
testAssert(
    str_contains($source, "'DISPATCHING'")
        && str_contains($source, 'jenxBuildStateRecover($requestId, 120)'),
    'stale DISPATCHING recovery is wired into collector'
);

printf("\n%d tests, %d failure(s).\n", $tests, $failures);

echo "Scope: offline collector function tests plus source checks. ";
echo "Production state-file I/O/recovery and full CLI exit paths are not executed.\n";

exit($failures === 0 ? 0 : 1);
