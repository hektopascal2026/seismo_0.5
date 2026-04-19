<?php
/**
 * Per-plugin "Recent runs" collapsible table (Diagnostics page).
 *
 * Rendered once per plugin card (core and third-party) from
 * `views/diagnostics.php`. Extracted as a partial to keep the two
 * otherwise-identical 30-line blocks in one place, matching the
 * partials pattern introduced in Slice 6.
 *
 * @var list<array{run_at: \DateTimeImmutable, status: string, item_count: int, error_message: ?string, duration_ms: int}> $hist
 */

declare(strict_types=1);

if ($hist === []) {
    return;
}
?>
<details style="margin-top: 10px;">
    <summary style="cursor: pointer; font-weight: 600; font-size: 0.9em;">Recent runs (<?= count($hist) ?>)</summary>
    <table style="width: 100%; font-size: 0.85em; margin-top: 8px; border-collapse: collapse;">
        <thead>
        <tr>
            <th style="text-align: left; border-bottom: 1px solid #ccc; padding: 4px;">Time (local)</th>
            <th style="border-bottom: 1px solid #ccc; padding: 4px;">Status</th>
            <th style="border-bottom: 1px solid #ccc; padding: 4px;">Items</th>
            <th style="border-bottom: 1px solid #ccc; padding: 4px;">ms</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($hist as $h): ?>
            <tr>
                <td style="padding: 4px 4px 4px 0;"><?= e((string)seismo_format_utc($h['run_at'], 'Y-m-d H:i:s')) ?></td>
                <td><?= e($h['status']) ?></td>
                <td><?= (int)$h['item_count'] ?></td>
                <td><?= (int)$h['duration_ms'] ?></td>
            </tr>
            <?php if (!empty($h['error_message'])): ?>
            <tr><td colspan="4" style="color: #900; padding-bottom: 6px;"><?= e((string)$h['error_message']) ?></td></tr>
            <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</details>
