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

require_once SEISMO_ROOT . '/views/helpers.php';

$fmt = static fn (int $n): string => number_format($n, 0, '.', ',');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About | <?= e(seismoBrandTitle()) ?></title>
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

        <main class="settings-container">
            <header class="settings-header">
                <h1>About Seismo</h1>
                <p class="subtitle">Version <?= e($seismoVersion) ?> &mdash; Legislative and media monitoring tool</p>
            </header>

            <?php if ($satellite): ?>
            <p class="meta-text">This install runs in <strong>satellite</strong> mode: entry rows are read from a mothership database; scores and Magnitu settings stay local to this instance.</p>
            <?php endif; ?>

            <section class="settings-section">
                <h2>What appears on your timeline</h2>
                <div class="table-responsive">
                    <table class="styleguide-table">
                        <thead>
                            <tr>
                                <th>Area</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Feeds</strong></td>
                                <td>RSS/Atom and Substack-style sources &mdash; manage rows under Feeds (Items / Feeds).</td>
                            </tr>
                            <tr>
                                <td><strong>Mail</strong></td>
                                <td>IMAP ingest into a unified <code>emails</code> table; subscriptions with domain-first matching (e.g. <code>@example.com</code>) under Mail.</td>
                            </tr>
                            <tr>
                                <td><strong>Scraper</strong></td>
                                <td>Scheduled page fetches with optional link-following &mdash; configure under Scraper.</td>
                            </tr>
                            <tr>
                                <td><strong>Leg</strong></td>
                                <td>Swiss Federal Assembly business (motions, sessions, publications, hearings) via the Parliament OData API &mdash; <em>not</em> a personal calendar.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="settings-section">
                <h2>Lex &mdash; Legislation &amp; Registers</h2>
                <p>Lex plugins share one table (<code>lex_items</code>) and appear on the same timeline as everything else:</p>
                <div class="table-responsive">
                    <table class="styleguide-table">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Mechanism</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>EU (EUR-Lex)</strong></td>
                                <td>SPARQL against the EU Publications Office CELLAR endpoint (CDM-oriented queries).</td>
                            </tr>
                            <tr>
                                <td><strong>Switzerland (Fedlex)</strong></td>
                                <td>SPARQL against the Fedlex SPARQL endpoint (federal law and treaties).</td>
                            </tr>
                            <tr>
                                <td><strong>Germany</strong></td>
                                <td>RSS from recht.bund.de (Bundesgesetzblatt).</td>
                            </tr>
                            <tr>
                                <td><strong>France</strong></td>
                                <td>L&eacute;gifrance via PISTE OAuth2 + search API (JORF-oriented filters).</td>
                            </tr>
                            <tr>
                                <td><strong>Parliament press (Parl MM / SDA)</strong></td>
                                <td>Swiss Parliament press releases and SDA-Meldungen via SharePoint list &rarr; <code>feed_items</code> as <code>parl_press</code> (core fetcher, not a Lex plugin).</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="settings-section">
                <h2>Magnitu &amp; Labels</h2>
                <p><strong>Highlights</strong> lists entries whose Magnitu score is at or above the alert threshold from Settings &rarr; Magnitu. You can sort the main timeline by relevance when that option is enabled.</p>
                <p>Magnitu uses four coarse labels (simplified):</p>
                <ul>
                    <li><strong>Investigation lead</strong> &mdash; worth opening as a possible story.</li>
                    <li><strong>Important</strong> &mdash; material you should not miss.</li>
                    <li><strong>Background</strong> &mdash; context worth keeping.</li>
                    <li><strong>Noise</strong> &mdash; low priority for your profile.</li>
                </ul>
                <p>API actions such as <code>magnitu_entries</code>, <code>magnitu_scores</code>, <code>magnitu_recipe</code>, <code>magnitu_labels</code>, and <code>magnitu_status</code> are documented alongside the Python client in the Magnitu repository (<a href="https://github.com/hektopascal2026/magnitu" target="_blank" rel="noopener">github.com/hektopascal2026/magnitu</a>) and in this repo&rsquo;s <code>.cursor/rules/magnitu-integration.mdc</code> for operators who deploy from Git.</p>
            </section>

            <section class="settings-section">
                <h2>Export API &mdash; Briefings &amp; Automation</h2>
                <p>Seismo is deliberately a <em>clean data provider</em>: scripts never had to scrape the HTML UI. Use a dedicated Bearer key stored as <code>export:api_key</code> (never the same value as the Magnitu write key):</p>
                <ul>
                    <li><code>?action=export_briefing</code> &mdash; Markdown digest for a time window.</li>
                    <li><code>?action=export_entries</code> &mdash; JSON with entries and score blocks.</li>
                </ul>
                <p><strong>0.4 &quot;AI view&quot;:</strong> The old read-only HTML page is not carried into 0.5. Use <code>Authorization: Bearer &lt;export:api_key&gt;</code> on the export actions instead &mdash; same data for LLMs, cron, n8n, or Raycast, without coupling to the dashboard markup.</p>
            </section>

            <section class="settings-section">
                <h2>Operations</h2>
                <ul>
                    <li><strong>Refresh:</strong> Timeline and Diagnostics share one pipeline (<code>RefreshAllService</code>); the Timeline top bar posts the same &quot;refresh all&quot; as Diagnostics.</li>
                    <li><strong>Cron:</strong> <code>refresh_cron.php</code> is CLI-only and mirrors that pipeline on a schedule.</li>
                    <li><strong>Retention:</strong> Per-family policies with a dry-run preview before destructive prune (Settings).</li>
                    <li><strong>Session login:</strong> Optional via <code>SEISMO_ADMIN_PASSWORD_HASH</code> in <code>config.local.php</code>; off by default so personal installs stay frictionless.</li>
                </ul>
            </section>

            <section class="settings-section">
                <h2>Data snapshot (this database)</h2>
                <?php if ($aboutStats !== null && $scoreCounts !== null): ?>
                <div class="table-responsive">
                    <table class="styleguide-table">
                        <thead>
                            <tr>
                                <th>Family</th>
                                <th>Rows</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Feed definitions (feeds)</td><td><?= e($fmt($aboutStats['feeds'])) ?></td></tr>
                            <tr><td>Feed items</td><td><?= e($fmt($aboutStats['feed_items'])) ?></td></tr>
                            <tr><td>Emails</td><td><?= e($fmt($aboutStats['emails'])) ?></td></tr>
                            <tr><td>Lex items</td><td><?= e($fmt($aboutStats['lex_items'])) ?></td></tr>
                            <tr><td>Leg / calendar events</td><td><?= e($fmt($aboutStats['calendar_events'])) ?></td></tr>
                            <tr><td>Scraper configs</td><td><?= e($fmt($aboutStats['scraper_configs'])) ?></td></tr>
                            <tr>
                                <td>Scores (all sources)</td>
                                <td><?= e($fmt($scoreCounts['total'])) ?> total &mdash; <?= e($fmt($scoreCounts['magnitu'])) ?> Magnitu, <?= e($fmt($scoreCounts['recipe'])) ?> recipe</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="meta-text">Live statistics could not be loaded (database unavailable).</p>
                <?php endif; ?>
            </section>

            <section class="settings-section">
                <h2>Brief history</h2>
                <p>Seismo grew in small public releases on PHP and MySQL. Many teams still keep a 0.4 install for side-by-side checks.</p>
                <ul class="about-history">
                    <li><strong>0.1 &mdash; RSS core (Jan 2026).</strong> SimplePie-based aggregation, unified feed, search, and the yellow-accent seismograph identity.</li>
                    <li><strong>0.2 &mdash; Mail &amp; Substack (Jan&ndash;Feb 2026).</strong> IMAP cron path, sender tags, Substack via RSS, expandable cards, styleguide &mdash; and an early <em>AI-readable HTML export</em> (ai_view), now superseded by the export API above.</li>
                    <li><strong>0.3 &mdash; Lex &amp; Magnitu foundation (Feb 2026).</strong> EU CELLAR + Fedlex SPARQL, Lex on the timeline, consolidated refresh, first Magnitu endpoints, Magnitu highlights page.</li>
                    <li><strong>0.4 &mdash; Full stack (Feb&ndash;Apr 2026).</strong> German and French Lex, Swiss Parliament press (SharePoint), parliamentary calendar, web scraper with link-following, hardened auth, <code>refresh_cron.php</code>, tabbed settings, email subscriptions (domain-first matching, unsubscribe headers), and module-owned Feeds/Mail admin. Swiss case law (BGer / BGE / BVGer via entscheidsuche.ch) shipped for many 0.4 databases &mdash; this 0.5 codebase focuses on the refactored plugin/repository architecture; additional families re-enter the same pattern when scheduled.</li>
                    <li><strong>0.5 &mdash; Consolidation.</strong> Native PHP 8.2 classes, repositories as the only SQL layer, <code>RefreshAllService</code> shared by web + cron, satellite mode for multi-profile Magnitu, retention service, export keys, first-run <code>?action=setup</code> stub, and the page you are reading &mdash; aligned with the developer README in the repo.</li>
                </ul>
            </section>

            <section class="settings-section">
                <h2>Magnitu companion (timeline)</h2>
                <p>Developed in parallel from 0.4 onward; major public milestones include TF-IDF baselines (v1), transformer distillation (v2), reliability work (v3), and multi-profile / satellite pairing (v4). Details live in the Magnitu repository readme.</p>

                <p class="meta-text meta-text--spaced">
                    Built by <a href="https://hektopascal.org" target="_blank" rel="noopener">hektopascal.org</a>.<br>
                    Developer migration log: <code>README-REORG.md</code> in the repository. This page is for people using the running site.
                </p>
            </section>
        </main>
    </div>
</body>
</html>
