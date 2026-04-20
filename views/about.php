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
<body class="about-page">
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
                <p class="subtitle">Legislative and media monitoring tool &mdash; Version <?= e($seismoVersion) ?></p>
            </header>

            <section class="settings-section">
                <h2>What is Seismo?</h2>
                <p>Seismo is a self-hosted monitoring dashboard that aggregates information from multiple sources into a single chronological feed. It tracks RSS feeds, email newsletters, Substack publications, legislative changes from the EU, Switzerland, Germany, and France, Swiss parliamentary press releases, Swiss case law, parliamentary calendars, and scraped web pages &mdash; helping you stay informed about policy, regulation, jurisprudence, and media that matter.</p>
            </section>

            <?php if ($satellite): ?>
            <section class="settings-section">
                <h2>This install</h2>
                <p>You are on a <strong>satellite</strong> instance: timeline rows are read from a mothership database, while scores and Magnitu settings stay on this machine.</p>
            </section>
            <?php endif; ?>

            <section class="settings-section">
                <h2>What appears on your timeline</h2>
                <p>Everything below merges into the same stream; use Feeds, Mail, Scraper, and Leg to tune what arrives.</p>
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
                <h2>What is Magnitu?</h2>
                <p>Magnitu is Seismo&rsquo;s companion scoring engine &mdash; an optional, local Python application that learns which entries matter to you and pushes relevance scores back to Seismo over HTTP.</p>
                <p>In the app, <strong>Highlights</strong> surfaces entries at or above your alert threshold (Settings &rarr; Magnitu). You can also sort the main timeline by relevance when that option is on.</p>

                <h3 class="about-subheading">How does it score?</h3>
                <p>Every entry can be scored on a four-level scale:</p>
                <ul>
                    <li><strong>Investigation Lead</strong> &mdash; could be the starting point of an investigative story.</li>
                    <li><strong>Important</strong> &mdash; a significant development you should be aware of.</li>
                    <li><strong>Background</strong> &mdash; contextual information, worth archiving.</li>
                    <li><strong>Noise</strong> &mdash; not relevant to your work.</li>
                </ul>
                <p>Scoring works at two levels. The <strong>deterministic recipe scorer</strong> runs inside Seismo &mdash; keyword weights and title boosting score new entries as soon as they are ingested. The <strong>Magnitu model</strong> is a full ML classifier (transformer embeddings, e.g. XLM-RoBERTa) that trains on your labels and posts richer scores via the API, overriding recipe scores when a model score is available.</p>
                <p class="meta-text">Machine endpoints (<code>magnitu_entries</code>, <code>magnitu_scores</code>, and related actions) are described in the <a href="https://github.com/hektopascal2026/magnitu" target="_blank" rel="noopener">Magnitu repository</a> for companion-app operators.</p>
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
                <h2>Version history</h2>
                <p>Seismo grew in small public releases on PHP and MySQL. The line below is the short story; operators still compare behaviour against a 0.4 install from time to time.</p>
                <div class="about-timeline">
                    <div class="about-timeline-entry">
                        <strong>v0.1 &ndash; v0.3 (Jan &ndash; Feb 2026):</strong> Initial prototypes. RSS, IMAP email fetching, Substack tracking, and the first SPARQL integrations for EU and Swiss legislation.
                    </div>
                    <div class="about-timeline-entry">
                        <strong>v0.4 (Feb 2026):</strong> The &ldquo;full stack&rdquo; update. German and French legislation, Swiss case law (Jus), and the parliamentary API. First Magnitu machine-learning integration and the tabbed settings interface.
                    </div>
                    <div class="about-timeline-entry">
                        <strong>v0.4.3 &ndash; v0.4.4 (Apr 2026):</strong> UX improvements. Email senders became first-class subscriptions with domain-first matching. Source management moved out of global Settings into dedicated module screens (Feeds, Mail, and friends).
                    </div>
                    <div class="about-timeline-entry">
                        <strong>v0.5 (current):</strong> The consolidation update &mdash; a major architectural rebuild.
                        <ul>
                            <li>Unified data fetching under one master cron (<code>refresh_cron.php</code>) that mirrors the web &ldquo;refresh all&rdquo; pipeline.</li>
                            <li>Replaced the procedural core with lightweight controllers, repositories, and fetcher services.</li>
                            <li>Hardened the surface with CSRF on mutating routes and a dormant session-auth layer you can switch on when you need it.</li>
                            <li>Retired the experimental HTML &ldquo;AI view&rdquo; in favour of the stable, stateless export API (see above).</li>
                        </ul>
                    </div>
                </div>
            </section>

            <section class="settings-section">
                <h2>Next steps: Seismo satellites</h2>
                <p>The next major theme is the full rollout of <strong>Seismo satellites</strong>.</p>
                <p>In a multi-profile setup, each topic profile (for example security or digital policy) can send scores to its own lightweight satellite Seismo instance. The satellite reads entries via cross-database queries from the primary <strong>mothership</strong> database, but keeps its own scoring tables. Different audiences get feeds ranked for their focus, without duplicating heavy ingestion and scraping infrastructure.</p>
                <p class="meta-text">Magnitu itself continues in parallel; release notes for the Python companion live in its repository.</p>
            </section>

            <section class="settings-section about-footer">
                <p>Built by <a href="https://hektopascal.org" target="_blank" rel="noopener">hektopascal.org</a>.</p>
                <p class="meta-text about-meta">Developer-facing migration detail: <code>README-REORG.md</code> in the repository. This page is for people using the running site.</p>
            </section>
        </main>
    </div>
</body>
</html>
