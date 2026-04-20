<?php
/**
 * @var string $basePath
 * @var string $csrfField
 * @var string|null $accent
 * @var string $headerTitle
 * @var string|null $headerSubtitle
 * @var string $activeNav
 */

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About — <?= e($headerTitle) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if (!empty($accent)): ?>
    <style>:root { --seismo-accent: <?= e((string)$accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?= e((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <article class="about-page" style="max-width: 44rem; line-height: 1.55;">
            <h1 style="margin-top: 0;">About Seismo</h1>
            <p>
                <strong><?= e($headerTitle) ?></strong> is a personal research radar for policy and media:
                it collects material from several kinds of sources, stores it in one database, and shows it as a
                single time-ordered stream on the <strong>Timeline</strong>. Entries can be highlighted by a
                local recipe and by the optional <strong>Magnitu</strong> machine-learning companion (v3), which
                syncs scores back into this app.
            </p>

            <h2>Sources you will see on the Timeline</h2>
            <ul>
                <li><strong>Feeds</strong> — RSS and Substack-style feeds you configure under Feeds.</li>
                <li><strong>Scraper</strong> — pages fetched on a schedule when a scraper source is enabled.</li>
                <li><strong>Mail</strong> — IMAP messages ingested when core mail fetch is configured.</li>
                <li><strong>Lex</strong> — legal gazette and register items (e.g. Swiss Fedlex, EUR-Lex, German and French portals), refreshed as plugins.</li>
                <li><strong>Leg</strong> — Swiss parliamentary business (motions, sessions, publications) from configured Leg sources — not a personal calendar.</li>
            </ul>

            <h2>Magnitu and scoring</h2>
            <p>
                The <strong>Highlights</strong> page lists entries whose Magnitu relevance score is at or above the
                alert threshold you set under Settings → Magnitu. The Timeline can optionally sort with relevance
                in mind. Recipe-based scoring remains a lightweight fallback; richer behaviour lives in Magnitu v3.
            </p>

            <h2>Machine-readable export (briefings and automation)</h2>
            <p>
                Seismo is designed to be a <strong>clean data provider</strong> for scripts, agents, and dashboards.
                The read-only HTTP export uses a dedicated Bearer key stored as <code>export:api_key</code> in the
                database (never the same value as the Magnitu write key). Typical calls:
            </p>
            <ul>
                <li><code>?action=export_briefing</code> — Markdown digest for a date range (ideal for LLM briefings).</li>
                <li><code>?action=export_entries</code> — JSON entries with score metadata.</li>
            </ul>
            <p>
                <strong>0.4 “AI view” replacement.</strong> The old HTML “AI view” from Seismo 0.4 is not carried forward
                in 0.5. Use the export API instead: authenticate with <code>Authorization: Bearer &lt;export:api_key&gt;</code>
                and pull <code>export_briefing</code> or <code>export_entries</code> into any client that speaks HTTP.
                That keeps briefings decoupled from the web UI and works the same from cron, n8n, or a local script.
            </p>

            <h2>Satellite installs</h2>
            <p>
                A <strong>satellite</strong> instance can read entry tables from a mothership database on the same MySQL
                server while keeping its own scores and Magnitu profile. Satellite mode is configured only in
                <code>config.local.php</code>; the Timeline and export APIs behave the same for Magnitu v3.
            </p>

            <p style="margin-top: 2rem; opacity: 0.85; font-size: 0.9rem;">
                Technical migration notes for developers live in <code>README-REORG.md</code> in the repository;
                this page is meant for people using the running site.
            </p>
        </article>
    </div>
</body>
</html>
