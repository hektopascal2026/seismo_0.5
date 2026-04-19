<?php
/** @var array<string, mixed> $data Provided by HealthController::show(). */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seismo health</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 720px;
            margin: 40px auto;
            padding: 0 20px;
            color: #000;
            line-height: 1.45;
        }
        h1 { margin: 0 0 24px; font-size: 22px; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 20px; margin: 0 0 24px; }
        dt { font-weight: 600; }
        code { background: #f4f4f4; padding: 1px 5px; border-radius: 3px; }
        .ok  { color: #2a7f2a; }
        .err { color: #b00020; }
        .note { font-size: 13px; color: #555; border-top: 1px solid #eee; padding-top: 16px; }
    </style>
</head>
<body>
    <h1>Seismo health</h1>

    <dl>
        <dt>Seismo</dt>
        <dd><?= htmlspecialchars((string)$data['seismoVersion']) ?></dd>

        <dt>PHP</dt>
        <dd><?= htmlspecialchars((string)$data['phpVersion']) ?></dd>

        <dt>Database</dt>
        <dd class="<?= $data['dbStatus'] === 'ok' ? 'ok' : 'err' ?>">
            <?= htmlspecialchars((string)$data['dbStatus']) ?>
            <?php if (!empty($data['dbVersion'])): ?>
                (MySQL <?= htmlspecialchars((string)$data['dbVersion']) ?>)
            <?php endif; ?>
        </dd>

        <dt>Schema version</dt>
        <dd>
            <?php if ($data['schemaVersion'] === null): ?>
                <span class="err">not initialised</span> — run <code>php migrate.php</code>
            <?php else: ?>
                <?= (int)$data['schemaVersion'] ?>
            <?php endif; ?>
        </dd>

        <dt>Mode</dt>
        <dd>
            <?php if ($data['satellite']): ?>
                satellite (reads from <code><?= htmlspecialchars((string)$data['mothershipDb']) ?></code>)
            <?php else: ?>
                mothership
            <?php endif; ?>
        </dd>

        <dt>Brand title</dt>
        <dd><?= htmlspecialchars((string)$data['brandTitle']) ?></dd>

        <dt>Base path</dt>
        <dd><code><?= htmlspecialchars($data['basePath'] === '' ? '/' : (string)$data['basePath']) ?></code></dd>
    </dl>

    <p class="note">
        This is the default action during Slice 0 of the 0.5 consolidation. Once
        the dashboard is ported, <code>?action=index</code> becomes the default
        and this page stays available at <code>?action=health</code> for
        uptime checks.
    </p>
</body>
</html>
