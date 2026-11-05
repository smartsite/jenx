<?php

declare(strict_types=1);

/**
 * Jen-X GitHub Actions API helper.
 *
 * Requires PHP cURL and the existing jenx-config.php loader.
 * Never logs or returns the GitHub token.
 */

function jenxGithubApiRequest(
    array $config,
    string $method,
    string $endpoint,
    ?array $payload = null
): array {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP cURL is not available.');
    }

    $method = strtoupper($method);

    if (!in_array($method, ['GET', 'POST'], true)) {
        throw new InvalidArgumentException('Unsupported GitHub API method.');
    }

    $repo = $config['GITHUB_REPOSITORY'] ?? '';
    $token = $config['GITHUB_TOKEN'] ?? '';
    $apiVersion = $config['GITHUB_API_VERSION'] ?? '';

    if (
       !preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repo) ||
       $token === '' ||
       preg_match('/[\r\n]/', $token) ||
       !preg_match('/^\d{4}-\d{2}-\d{2}$/', $apiVersion)
    ) {
         throw new RuntimeException('GitHub API configuration is invalid.');
    }

    $allowedPrefix = '/repos/' . $repo;

    if (
       !(
           $endpoint === $allowedPrefix ||
           str_starts_with($endpoint, $allowedPrefix . '/')
       ) || 
        preg_match('~(?:^|/)\.\.(?:/|$)~', $endpoint) ||
        str_contains($endpoint, '\\') ||
        str_contains($endpoint, '#') ||
        preg_match('/[\r\n\s]/', $endpoint)
    ) {
        throw new InvalidArgumentException('GitHub API endpoint is invalid.');
    }

    $responseHeaders = [];
    $responseBody = '';
    $maxResponseBytes = 2 * 1024 * 1024;

    $curl = curl_init('https://api.github.com' . $endpoint);

    if ($curl === false) {
        throw new RuntimeException('Could not initialise GitHub API request.');
    }

    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: ' . $apiVersion,
        'User-Agent: Jen-X-Controller',
    ];

    if ($payload !== null) {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    } else {
        $jsonPayload = null;
    }

    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $name = strtolower(trim($parts[0]));
                $responseHeaders[$name] = trim($parts[1]);
            }

            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (
            &$responseBody,
            $maxResponseBytes
        ): int {
            if (strlen($responseBody) + strlen($chunk) > $maxResponseBytes) {
                return 0;
            }

            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ]);

    if ($jsonPayload !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $jsonPayload);
    }

    $executed = curl_exec($curl);
    $transportError = $executed === false ? curl_error($curl) : null;
    $transportErrno = curl_errno($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    $decoded = null;
    if ($responseBody !== '') {
        $decoded = json_decode($responseBody, true);
    }

    return [
        'http_status' => $httpStatus,
        'headers' => $responseHeaders,
        'body' => $responseBody,
        'json' => is_array($decoded) ? $decoded : null,
        'transport_error' => $transportError,
        'transport_errno' => $transportErrno,
    ];
}


/**
 * Dispatch exactly one build request.
 *
 * This function intentionally does not retry POST requests.
 * A transport failure must be reconciled before considering another dispatch.
 */


/**
 * Dispatch exactly one build request.
 *
 * This function intentionally does not retry POST requests.
 * A transport failure must be reconciled before considering another dispatch.
 */


/**
 * Dispatch exactly one build request.
 *
 * The expected source commit is pinned by the controller and passed to
 * the workflow to prevent a moved release tag changing the build source.
 *
 * This function intentionally does not retry POST requests.
 * A transport failure must be reconciled before considering another dispatch.
 */
function jenxGithubDispatchWorkflow(
    array $config,
    string $releaseTag,
    string $requestId,
    string $expectedSourceCommit,
    bool $testOnly = false
): array {
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $releaseTag)) {
        throw new InvalidArgumentException('Release tag format is invalid.');
    }

    if (!preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $requestId
    )) {
        throw new InvalidArgumentException('Request ID must be a UUID.');
    }

    if (!preg_match('/^[0-9a-f]{40}$/i', $expectedSourceCommit)) {
        throw new InvalidArgumentException(
            'Expected source commit must be a full 40-character SHA.'
        );
    }

    $workflowId = $config['GITHUB_WORKFLOW_ID'] ?? '';
    $branch = $config['BUILD_BRANCH'] ?? '';

    if (!ctype_digit((string) $workflowId) || $branch !== 'portable') {
        throw new RuntimeException('Workflow configuration is invalid.');
    }

    $endpoint = '/repos/' . $config['GITHUB_REPOSITORY']
        . '/actions/workflows/' . $workflowId . '/dispatches';

    return jenxGithubApiRequest($config, 'POST', $endpoint, [
        'ref' => 'portable',
        'inputs' => [
            'release_tag' => $releaseTag,
            'request_id' => strtolower($requestId),
            'expected_source_commit' => strtolower($expectedSourceCommit),
            'test_only' => $testOnly,
        ],
    ]);
}



/** List recent runs for the configured workflow on portable. */
function jenxGithubListWorkflowRuns(array $config): array
{
    $workflowId = $config['GITHUB_WORKFLOW_ID'] ?? '';

    if (!ctype_digit((string) $workflowId)) {
        throw new RuntimeException('Workflow ID configuration is invalid.');
    }

    $endpoint = '/repos/' . $config['GITHUB_REPOSITORY']
        . '/actions/workflows/' . $workflowId
        . '/runs?branch=portable&event=workflow_dispatch&per_page=100';

    return jenxGithubApiRequest($config, 'GET', $endpoint);
}


/** Retrieve details for one workflow run. */
function jenxGithubGetWorkflowRun(array $config, int $runId): array
{
    if ($runId < 1) {
        throw new InvalidArgumentException('Workflow run ID is invalid.');
    }

    $endpoint = '/repos/' . $config['GITHUB_REPOSITORY']
        . '/actions/runs/' . $runId;

    return jenxGithubApiRequest($config, 'GET', $endpoint);
}


/** List artifacts attached to one workflow run. */
function jenxGithubListRunArtifacts(array $config, int $runId): array
{
    if ($runId < 1) {
        throw new InvalidArgumentException('Workflow run ID is invalid.');
    }

    $endpoint = '/repos/' . $config['GITHUB_REPOSITORY']
        . '/actions/runs/' . $runId . '/artifacts?per_page=100';

    return jenxGithubApiRequest($config, 'GET', $endpoint);
}


/**
 * Download a GitHub Actions artifact ZIP to a temporary file, then rename it
 * to the requested destination after a successful download.
 *
 * GitHub's API redirect is not followed automatically. The GitHub token is
 * never sent to the signed artifact-download URL.
 */
function jenxGithubDownloadArtifactZip(
    array $config,
    int $artifactId,
    string $destination
): array {
    if ($artifactId < 1) {
        throw new InvalidArgumentException('Artifact ID is invalid.');
    }

    $endpoint = '/repos/' . $config['GITHUB_REPOSITORY']
        . '/actions/artifacts/' . $artifactId . '/zip';

    $response = jenxGithubApiRequest($config, 'GET', $endpoint);

    if ($response['transport_error'] !== null) {
        return [
            'ok' => false,
            'error' => 'Artifact download request failed at the transport layer.',
            'response' => $response,
        ];
    }

    if ($response['http_status'] !== 302) {
        return [
            'ok' => false,
            'error' => 'GitHub did not return the expected artifact redirect.',
            'response' => $response,
        ];
    }

    $location = $response['headers']['location'] ?? '';
    $url = parse_url($location);

    if (
        !is_array($url) ||
        strtolower($url['scheme'] ?? '') !== 'https' ||
        empty($url['host']) ||
        isset($url['user']) ||
        isset($url['pass']) ||
        (isset($url['port']) && $url['port'] !== 443)
    ) {
        return ['ok' => false, 'error' => 'Artifact redirect URL is invalid.'];
    }

    $host = strtolower($url['host']);

    // GitHub-hosted Actions artifacts use Azure Blob Storage download URLs.
    // Reject other hosts rather than risk sending a request to an arbitrary site.
    if (!str_ends_with($host, '.blob.core.windows.net')) {
        return ['ok' => false, 'error' => 'Artifact redirect host is not allowed.'];
    }

    $directory = dirname($destination);

    if (!is_dir($directory) || !is_writable($directory)) {
        return ['ok' => false, 'error' => 'Artifact destination directory is unavailable.'];
    }

    $temporaryPath = tempnam($directory, '.jenx-artifact-');

    if ($temporaryPath === false) {
        return ['ok' => false, 'error' => 'Could not create temporary artifact file.'];
    }

    $file = fopen($temporaryPath, 'wb');

    if ($file === false) {
        @unlink($temporaryPath);
        return ['ok' => false, 'error' => 'Could not open temporary artifact file.'];
    }

    $bytesWritten = 0;
    $maxArtifactBytes = 5 * 1024 * 1024;
    $curl = curl_init($location);

    if ($curl === false) {
        fclose($file);
        @unlink($temporaryPath);
        return ['ok' => false, 'error' => 'Could not initialise artifact download.'];
    }

    curl_setopt_array($curl, [
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (
            $file,
            &$bytesWritten,
            $maxArtifactBytes
        ): int {
            $length = strlen($chunk);

            if ($bytesWritten + $length > $maxArtifactBytes) {
                return 0;
            }

            $written = fwrite($file, $chunk);

            if ($written === false || $written !== $length) {
                return 0;
            }

            $bytesWritten += $written;
            return $written;
        },
    ]);

    $executed = curl_exec($curl);
    $downloadError = $executed === false ? curl_error($curl) : null;
    $downloadErrno = curl_errno($curl);
    $downloadStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);
    fclose($file);

    if ($downloadError !== null || $downloadStatus !== 200 || $bytesWritten < 1) {
        @unlink($temporaryPath);

        return [
            'ok' => false,
            'error' => 'Artifact ZIP download failed.',
            'http_status' => $downloadStatus,
            'transport_error' => $downloadError,
            'transport_errno' => $downloadErrno,
        ];
    }

    if (!rename($temporaryPath, $destination)) {
        @unlink($temporaryPath);
        return ['ok' => false, 'error' => 'Could not finalise artifact file.'];
    }

    return [
        'ok' => true,
        'http_status' => $downloadStatus,
        'bytes' => $bytesWritten,
        'path' => $destination,
    ];
}
