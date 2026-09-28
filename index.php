<?php

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$stateDir = __DIR__ . '/state';
$currentFile = $stateDir . '/current.json';
$historyFile = $stateDir . '/releases.log';

$current = null;
$history = [];

if (is_file($currentFile)) {
    $data = file_get_contents($currentFile);
    $current = json_decode($data, true);
}

if (is_file($historyFile)) {
    $lines = file($historyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $release = json_decode($line, true);

        if (is_array($release)) {
            $history[] = $release;
        }
    }

    $history = array_reverse($history);
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatDateTime($value)
{
    $date = new DateTime($value, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('Australia/Sydney'));

    return $date->format('d M Y, H:i:s T');
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jen-X — Foundd Releases</title>

    <style>
        body {
            margin: 0;
            padding: 40px;
            background: #f4f5f7;
            color: #222;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        header {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 35px;
        }

        .logo {
            width: 72px;
            height: 72px;
            flex: 0 0 72px;
            background-image: url('/jenx/logo.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            border-radius: 8px;
        }

        h1 {
            margin: 0;
            font-size: 32px;
        }

        .subtitle {
            color: #666;
            margin-top: 5px;
        }

        .card {
            background: white;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,.12);
        }

        .status {
            font-size: 22px;
            font-weight: bold;
        }

        .success {
            color: #16803c;
        }

        .meta {
            color: #666;
            margin-top: 8px;
        }

        code {
            background: #f0f1f3;
            padding: 2px 5px;
            border-radius: 3px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }

        th {
            color: #666;
            font-size: 13px;
            text-transform: uppercase;
        }
    </style>
</head>

<body>

<div class="container">

    <header>
        <div class="logo"></div>

        <div>
            <!--<h1>Jen-X</h1>-->
            <div class="subtitle">Ultralite Jenkins™ release control</div>
        </div>
    </header>

    <div class="card">

        <h2>Current release</h2>

        <?php if ($current): ?>

            <div class="status success">
                Release successful
            </div>

            <div class="meta">
                Commit:
                <code><?= h($current['commit'] ?? '') ?></code>
            </div>

            <div class="meta">
                Released:
                <?= h(formatDateTime($current['releasedAt'] ?? '')) ?>
            </div>

            <div class="meta">
                Health:
                HTTP <?= h($current['health'] ?? '') ?>
            </div>

        <?php else: ?>

            <div class="status">
                No release recorded
            </div>

        <?php endif; ?>

    </div>

    <div class="card">

        <h2>Release history</h2>

        <?php if ($history): ?>

            <table>
                <thead>
                    <tr>
                        <th>Released</th>
                        <th>Commit</th>
                        <th>Status</th>
                        <th>Health</th>
                    </tr>
                </thead>

                <tbody>

                <?php foreach ($history as $release): ?>

                    <tr>
                        <td><?= h(formatDateTime($release['releasedAt'] ?? '')) ?></td>
                        <td>
                            <code><?= h($release['commit'] ?? '') ?></code>
                        </td>
                        <td><?= h($release['status'] ?? '') ?></td>
                        <td>HTTP <?= h($release['health'] ?? '') ?></td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>

        <?php else: ?>

            <p>No releases recorded yet.</p>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
