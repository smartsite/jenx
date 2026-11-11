<?php

declare(strict_types=1);

/**
 * Jen-X command bridge.
 *
 * Thin command gateway for the web/API tier.
 * Deployment authority remains jenx-env.php.
 *
 * Usage:
 *   php jenx-api.php deploy <env> <sha256:digest> [--confirm-prod]
 *   php jenx-api.php promote <source-env> <target-env> [--confirm-prod]
 *   php jenx-api.php rollback <env> [--confirm-prod]
 *   php jenx-api.php status
 */

const JENX_ENV_CLI = __DIR__ . '/jenx-env.php';

function jenxApiUsage(): never
{
    fwrite(STDERR, <<<TXT
Usage:
  php jenx-api.php deploy <env> <sha256:digest> [--confirm-prod]
  php jenx-api.php promote <source-env> <target-env> [--confirm-prod]
  php jenx-api.php rollback <env> [--confirm-prod]
  php jenx-api.php status
  php jenx-api.php build-list

TXT);

    exit(2);
}

function jenxApiEnvironment(string $value): bool
{
    return in_array($value, ['dev', 'uat', 'prod'], true);
}

function jenxApiDigest(string $value): bool
{
    return preg_match('/^sha256:[a-f0-9]{64}$/', $value) === 1;
}

$args = array_slice($argv, 1);

if ($args === []) {
    jenxApiUsage();
}

$command = $args[0];

switch ($command) {

    case 'deploy':
        if (
            !isset($args[1], $args[2])
            || !jenxApiEnvironment($args[1])
            || !jenxApiDigest($args[2])
            || count($args) > 4
            || (isset($args[3]) && $args[3] !== '--confirm-prod')
        ) {
            jenxApiUsage();
        }

        $commandArgs = [
            JENX_ENV_CLI,
            'deploy',
            $args[1],
            $args[2],
        ];

        if (($args[3] ?? null) === '--confirm-prod') {
            $commandArgs[] = '--confirm-prod';
        }

        $commandArgs[] = '--json';
        break;

    case 'promote':
        if (
            !isset($args[1], $args[2])
            || !jenxApiEnvironment($args[1])
            || !jenxApiEnvironment($args[2])
            || count($args) > 4
            || (isset($args[3]) && $args[3] !== '--confirm-prod')
        ) {
            jenxApiUsage();
        }

        $commandArgs = [
            JENX_ENV_CLI,
            'promote',
            $args[1],
            $args[2],
        ];

        if (($args[3] ?? null) === '--confirm-prod') {
            $commandArgs[] = '--confirm-prod';
        }

        $commandArgs[] = '--json';
        break;

    case 'rollback':
        if (
            !isset($args[1])
            || !jenxApiEnvironment($args[1])
            || count($args) > 3
            || (isset($args[2]) && $args[2] !== '--confirm-prod')
        ) {
            jenxApiUsage();
        }

        $commandArgs = [
            JENX_ENV_CLI,
            'rollback',
            $args[1],
        ];

        if (($args[2] ?? null) === '--confirm-prod') {
            $commandArgs[] = '--confirm-prod';
        }

        $commandArgs[] = '--json';
        break;

    case 'status':
        if (count($args) !== 1) {
            jenxApiUsage();
        }

        $commandArgs = [
            JENX_ENV_CLI,
            'status',
            '--json',
        ];
        break;

    case 'build-list':
        if (count($args) !== 1) {
            jenxApiUsage();
        }

        $commandArgs = [
            __DIR__ . '/jenx-build-list.php',
        ];
        break;

    case 'build-status':
        if (count($args) !== 2) {
           jenxApiUsage();
        }

        $commandArgs = [
            __DIR__ . '/jenx-build-status.php',
           $args[1],
        ];
        break;

    default:
        jenxApiUsage();
}

$command = escapeshellarg(PHP_BINARY);
foreach ($commandArgs as $argument) {
    $command .= ' ' . escapeshellarg($argument);
}

passthru($command, $exitCode);

exit($exitCode);
