<?php
/**
 * @var string $basePath
 * @var string $csrfField
 * @var string|null $accent
 * @var string $headerTitle
 * @var string|null $headerSubtitle
 * @var string $activeNav
 * @var ?array{
 *   feeds: int,
 *   feed_items: int,
 *   emails: int,
 *   lex_items: int,
 *   calendar_events: int,
 *   scraper_configs: int
 * } $aboutStats
 * @var ?array{total: int, magnitu: int, recipe: int} $scoreCounts
 * @var string $seismoVersion
 * @var bool $satellite
 */

declare(strict_types=1);

$fmt = static fn (int $n): string => number_format($n, 0, '.', ',');
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

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message message-success"><?= e((string)$_SESSION['success']) ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message message-error"><?= e((string)$_SESSION['error']) ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <article class="about-page">
            <header class="about-hero">
                <h1>About <?= e($headerTitle) ?></h1>
                <p class="about-lede">
                    <strong><?= e($headerTitle) ?></strong> is a self-hosted monitoring dashboard: it pulls
                    <strong>RSS</strong> and <strong>Substack-style</strong> feeds, <strong>IMAP mail</strong>,
                    <strong>scraped pages</strong>, <strong>legal gazette updates (Lex)</strong>, and
                    <strong>Swiss parliamentary business (Leg)</strong> into one <strong>unified timeline</strong>
                    with search, favourites, and filter pills.
                    <?php if ($satellite): ?>
                        This install runs in <strong>satellite</strong> mode — it reads entry data from a mothership database and keeps its own scores and Magnitu profile.
                    <?php endif; ?>
                </p>
                <p class="about-lede">
                    A <strong>recipe engine</strong> in PHP scores entries immediately from keywords and weights.
                    Optionally, <strong>Magnitu v3</strong> (a Python companion maintained alongside Seismo) learns from your labels and pushes
                    <strong>relevance scores</strong> back over a small HTTP API. A separate <strong>read-only export API</strong> feeds Markdown or JSON to LLMs and automation — without granting the Magnitu write key.
                </p>
            </header>

            <h2>What appears on your timeline</h2>
            <div class="about-table-wrap">
                <table class="about-table">
                    <thead>
                        <tr><th>Area</th><th>Description</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Feeds</strong></td>
                            <td>RSS/Atom and Substack-style sources — manage rows under <strong>Feeds</strong> (Items / Feeds).</td>
                        </tr>
                        <tr>
                            <td><strong>Mail</strong></td>
                            <td>IMAP ingest into a unified <code>emails</code> table; subscriptions with domain-first matching (e.g. <code>@example.com</code>) under <strong>Mail</strong>.</td>
                        </tr>
                        <tr>
                            <td><strong>Scraper</strong></td>
                            <td>Scheduled page fetches with optional link-following — configure under <strong>Scraper</strong>.</td>
                        </tr>
                        <tr>
                            <td><strong>Leg</strong></td>
                            <td>Swiss Federal Assembly business (motions, sessions, publications, hearings) via the Parliament OData API — <em>not</em> a personal calendar.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2>Lex — legislation &amp; registers</h2>
            <p>Lex plugins share one table (<code>lex_items</code>) and appear on the same timeline as everything else:</p>
            <div class="about-table-wrap">
                <table class="about-table">
                    <thead>
                        <tr><th>Source</th><th>Mechanism</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>EU (EUR-Lex)</strong></td>
                            <td>SPARQL against the EU Publications Office <a href="https://publications.europa.eu/webapi/rdf/sparql" rel="noopener noreferrer">CELLAR</a> endpoint (CDM-oriented queries).</td>
                        </tr>
                        <tr>
                            <td><strong>Switzerland (Fedlex)</strong></td>
                            <td>SPARQL against the <a href="https://fedlex.data.admin.ch/sparql" rel="noopener noreferrer">Fedlex SPARQL endpoint</a> (federal law and treaties).</td>
                        </tr>
                        <tr>
                            <td><strong>Germany</strong></td>
                            <td>RSS from <a href="https://www.recht.bund.de/" rel="noopener noreferrer">recht.bund.de</a> (Bundesgesetzblatt).</td>
                        </tr>
                        <tr>
                            <td><strong>France</strong></td>
                            <td><a href="https://www.legifrance.gouv.fr/" rel="noopener noreferrer">Légifrance</a> via <a href="https://piste.gouv.fr/" rel="noopener noreferrer">PISTE</a> OAuth2 + search API (JORF-oriented filters).</td>
                        </tr>
                        <tr>
                            <td><strong>Parliament press (Parl MM / SDA)</strong></td>
                            <td>Swiss Parliament <a href="https://www.parlament.ch/en/pages/home.aspx" rel="noopener noreferrer">press releases</a> via SharePoint list → <code>feed_items</code> as <code>parl_press</code> (core fetcher, not a Lex plugin).</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h2>Magnitu &amp; labels</h2>
            <p>
                <strong><a href="<?= e($basePath) ?>/index.php?action=magnitu">Highlights</a></strong> lists entries whose Magnitu score is at or above the alert threshold from <strong>Settings → Magnitu</strong>.
                You can sort the main timeline by relevance when that option is enabled.
            </p>
            <p>Magnitu uses four coarse labels (simplified):</p>
            <ul>
                <li><strong>Investigation lead</strong> — worth opening as a possible story.</li>
                <li><strong>Important</strong> — material you should not miss.</li>
                <li><strong>Background</strong> — context worth keeping.</li>
                <li><strong>Noise</strong> — low priority for your profile.</li>
            </ul>
            <p>
                API actions such as <code>magnitu_entries</code>, <code>magnitu_scores</code>, <code>magnitu_recipe</code>, <code>magnitu_labels</code>, and <code>magnitu_status</code> are documented alongside the Python client in the Magnitu repository
                (<a href="https://github.com/hektopascal2026/magnitu" rel="noopener noreferrer">github.com/hektopascal2026/magnitu</a>) and in this repo’s <code>.cursor/rules/magnitu-integration.mdc</code> for operators who deploy from Git.
            </p>

            <h2>Export API — briefings &amp; automation</h2>
            <p>
                Seismo is deliberately a <strong>clean data provider</strong>: scripts never had to scrape the HTML UI.
                Use a dedicated Bearer key stored as <code>export:api_key</code> (never the same value as the Magnitu write key):
            </p>
            <ul>
                <li><code>?action=export_briefing</code> — Markdown digest for a time window.</li>
                <li><code>?action=export_entries</code> — JSON with entries and score blocks.</li>
            </ul>
            <p>
                <strong>0.4 “AI view”. </strong> The old read-only HTML page is not carried into 0.5. Use <code>Authorization: Bearer &lt;export:api_key&gt;</code> on the export actions instead — same data for LLMs, cron, n8n, or Raycast, without coupling to the dashboard markup.
            </p>

            <h2>Operations</h2>
            <ul>
                <li><strong>Refresh</strong> — Timeline and Diagnostics share one pipeline (<code>RefreshAllService</code>); the Timeline top bar posts the same “refresh all” as Diagnostics.</li>
                <li><strong>Cron</strong> — <code>refresh_cron.php</code> is CLI-only and mirrors that pipeline on a schedule.</li>
                <li><strong>Retention</strong> — per-family policies with a dry-run preview before destructive prune (Settings).</li>
                <li><strong>Session login</strong> — optional via <code>SEISMO_ADMIN_PASSWORD_HASH</code> in <code>config.local.php</code>; off by default so personal installs stay frictionless.</li>
            </ul>

            <?php if ($aboutStats !== null && $scoreCounts !== null): ?>
            <h2>Data snapshot <span class="about-meta">(this database)</span></h2>
            <div class="about-table-wrap">
                <table class="about-table">
                    <thead>
                        <tr><th>Family</th><th>Rows</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>Feed definitions (<code>feeds</code>)</td><td><?= e($fmt($aboutStats['feeds'])) ?></td></tr>
                        <tr><td>Feed items</td><td><?= e($fmt($aboutStats['feed_items'])) ?></td></tr>
                        <tr><td>Emails</td><td><?= e($fmt($aboutStats['emails'])) ?></td></tr>
                        <tr><td>Lex items</td><td><?= e($fmt($aboutStats['lex_items'])) ?></td></tr>
                        <tr><td>Leg / calendar events</td><td><?= e($fmt($aboutStats['calendar_events'])) ?></td></tr>
                        <tr><td>Scraper configs</td><td><?= e($fmt($aboutStats['scraper_configs'])) ?></td></tr>
                        <tr><td>Scores (all sources)</td><td><?= e($fmt($scoreCounts['total'])) ?> total — <?= e($fmt($scoreCounts['magnitu'])) ?> Magnitu, <?= e($fmt($scoreCounts['recipe'])) ?> recipe</td></tr>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="about-meta">Live statistics could not be loaded (database unavailable).</p>
            <?php endif; ?>

            <h2>Brief history</h2>
            <p class="about-meta">
                Seismo grew in small public releases on PHP and MySQL. Many teams still keep a <strong>0.4</strong> install for side-by-side checks — for example
                <a href="https://www.hektopascal.org/seismo-staging/?action=about" rel="noopener noreferrer">hektopascal.org staging (0.4 About)</a>.
            </p>
            <ul class="about-history">
                <li>
                    <strong>0.1 — RSS core (Jan 2026).</strong> SimplePie-based aggregation, unified feed, search, and the yellow-accent seismograph identity.
                </li>
                <li>
                    <strong>0.2 — Mail &amp; Substack (Jan–Feb 2026).</strong> IMAP cron path, sender tags, Substack via RSS, expandable cards, styleguide — and an early <strong>AI-readable HTML export</strong> (<code>ai_view</code>), now superseded by the <strong>export API</strong> above.
                </li>
                <li>
                    <strong>0.3 — Lex &amp; Magnitu foundation (Feb 2026).</strong> EU CELLAR + Fedlex SPARQL, Lex on the timeline, consolidated refresh, first Magnitu endpoints (<code>magnitu_entries</code>, <code>magnitu_scores</code>, <code>magnitu_recipe</code>, labels), Magnitu highlights page.
                </li>
                <li>
                    <strong>0.4 — Full stack (Feb–Apr 2026).</strong> German and French Lex, Swiss Parliament press (SharePoint), parliamentary <strong>calendar</strong>, web scraper with link-following, hardened auth, <code>refresh_cron.php</code>, tabbed settings, email <strong>subscriptions</strong> (domain-first matching, unsubscribe headers), and module-owned Feeds/Mail admin. Swiss <strong>case law</strong> (BGer / BGE / BVGer via entscheidsuche.ch) shipped for many 0.4 databases — this <strong>0.5</strong> codebase focuses on the refactored plugin/repository architecture; additional families re-enter the same pattern when scheduled.
                </li>
                <li>
                    <strong>0.5 — Consolidation.</strong> Native PHP 8.2 classes, repositories as the only SQL layer, <code>RefreshAllService</code> shared by web + cron, satellite mode for multi-profile Magnitu, retention service, export keys, first-run <code>?action=setup</code> stub, and the page you are reading — aligned with the developer <a href="https://github.com/hektopascal2026/seismo_0.5/blob/main/README.md" rel="noopener noreferrer">README</a> in the repo.
                </li>
            </ul>

            <h2>Magnitu companion (timeline)</h2>
            <p class="about-meta">Developed in parallel from 0.4 onward; major public milestones include TF-IDF baselines (v1), transformer distillation (v2), reliability work (v3), and multi-profile / satellite pairing (v4). Details live in the Magnitu repository readme.</p>

            <footer class="about-footer">
                <p>
                    Built by <a href="https://www.hektopascal.org/" rel="noopener noreferrer">hektopascal.org</a>.
                    Seismo <strong><?= e($seismoVersion) ?></strong><?php if ($aboutStats !== null): ?> · counts above reflect the connected database<?php endif; ?>.
                </p>
                <p class="about-meta">
                    Developer migration log: <code>README-REORG.md</code> in the repository. This page is for people using the running site.
                </p>
            </footer>
        </article>
    </div>
</body>
</html>
