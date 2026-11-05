<?php

declare(strict_types=1);

/**
 * Load and validate Jen-X configuration.
 *
 * The environment file is deliberately parsed as data rather than
 * executed as PHP or sourced as a shell script.
 */

function jenxLoadConfig(
    string $path = '/etc/jenx/jenx.env'
): array {
    if (!is_readable($path)) {
        throw new RuntimeException(
            'Jen-X configuration is unavailable.'
        );
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new RuntimeException(
            'Jen-X configuration could not be read.'
        );
    }

    $config = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (!preg_match(
            '/^([A-Z][A-Z0-9_]*)=(.*)$/',
            $line,
            $matches
        )) {
            throw new RuntimeException(
                'Jen-X configuration contains an invalid entry.'
            );
        }

        $key = $matches[1];
        $value = trim($matches[2]);

        if (array_key_exists($key, $config)) {
            throw new RuntimeException(
                'Jen-X configuration contains a duplicate key.'
            );
        }

        if ($value === '') {
            throw new RuntimeException(
                'Jen-X configuration contains an empty value.'
            );
        }

        $config[$key] = $value;
    }

    $required = [
        'GITHUB_TOKEN',
        'GITHUB_REPOSITORY',
        'GITHUB_WORKFLOW_ID',
        'GITHUB_API_VERSION',
        'IMAGE_NAME',
        'BUILD_BRANCH',
        'BUILD_ARTIFACT_PREFIX',
    ];

    foreach ($required as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new RuntimeException(
                'Jen-X configuration is missing a required setting.'
            );
        }
    }

    if (!preg_match(
        '/^[A-Za-z0-9-]+\/[A-Za-z0-9_.-]+$/',
        $config['GITHUB_REPOSITORY']
    )) {
        throw new RuntimeException(
            'Jen-X repository configuration is invalid.'
        );
    }

    if (!ctype_digit($config['GITHUB_WORKFLOW_ID'])) {
        throw new RuntimeException(
            'Jen-X workflow ID configuration is invalid.'
        );
    }

    if ($config['BUILD_BRANCH'] !== 'portable') {
        throw new RuntimeException(
            'Jen-X build branch must be portable.'
        );
    }

    if (!preg_match(
        '/^ghcr\.io\/[a-z0-9_.-]+\/[a-z0-9_.-]+$/',
        $config['IMAGE_NAME']
    )) {
        throw new RuntimeException(
            'Jen-X image configuration is invalid.'
        );
    }

    if (!preg_match(
        '/^[A-Za-z0-9_.-]+$/',
        $config['BUILD_ARTIFACT_PREFIX']
    )) {
        throw new RuntimeException(
            'Jen-X artifact prefix configuration is invalid.'
        );
    }

    if (!preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $config['GITHUB_API_VERSION']
    )) {
        throw new RuntimeException(
            'Jen-X API version configuration is invalid.'
        );
    }

    return $config;
}
