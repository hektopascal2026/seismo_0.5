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

        <main class="settings-container about-modern-layout">
            <header class="settings-header">
                <h1>About Seismo</h1>
                <p class="subtitle">Legislative and media monitoring tool &mdash; Version <?= e($seismoVersion) ?></p>
            </header>

            <!-- I. Overview Card -->
            <section class="settings-section about-card">
                <h2>I. What is Seismo?</h2>
                <p class="about-lede">Seismo is a professional-grade monitoring dashboard designed to aggregate disparate information streams into a single, unified chronological feed.</p>
                <div class="about-grid">
                    <div class="about-grid-item">
                        <strong>Unified Monitoring</strong>
                        <p>Track policy, regulation, and media across multiple jurisdictions from one interface.</p>
                    </div>
                    <div class="about-grid-item">
                        <strong>Self-Hosted Control</strong>
                        <p>Keep your data and monitoring preferences private with a local-first architecture.</p>
                    </div>
                </div>

                <?php if ($satellite): ?>
                <div class="admin-help" style="margin-top: 1rem;">
                    <strong>Satellite Instance:</strong> This installation reads timeline data from a central <em>mothership</em> database while maintaining local scores and Magnitu preferences.
                </div>
                <?php endif; ?>
            </section>

            <!-- II. Architecture & Sources -->
            <section class="settings-section about-card">
                <h2>II. Architecture & Data Sources</h2>
                <p>Everything you track merges into a single stream. You can tune these sources via their respective management screens.</p>
                
                <div class="table-responsive">
                    <table class="styleguide-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Coverage & Mechanism</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Direct Ingest</strong></td>
                                <td>
                                    <strong>Feeds:</strong> RSS/Atom and Substack publications.<br>
                                    <strong>Mail:</strong> IMAP ingest with domain-first matching (e.g., <code>@example.com</code>).<br>
                                    <strong>Scraper:</strong> Scheduled fetches of complex web pages.
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Lex Plugins</strong></td>
                                <td>
                                    <strong>EU:</strong> EUR-Lex via SPARQL (CELLAR endpoint).<br>
                                    <strong>Switzerland:</strong> Fedlex via SPARQL (Federal Law & Treaties).<br>
                                    <strong>Germany:</strong> recht.bund.de (Bundesgesetzblatt) via RSS.<br>
                                    <strong>France:</strong> Légifrance via PISTE OAuth2 & Search API.
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Intelligence</strong></td>
                                <td>
                                    <strong>Leg:</strong> Swiss Parliament OData API (Motions, Sessions, Hearings).<br>
                                    <strong>Press:</strong> Swiss Parliament press releases via SharePoint.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- III. Scoring Intelligence -->
            <section class="settings-section about-card">
                <h2>III. Intelligence: Magnitu Engine</h2>
                <p>Magnitu is Seismo’s companion scoring engine—an optional Python application that learns what matters to you.</p>
                
                <div class="about-scoring-levels">
                    <div class="scoring-level">
                        <span class="level-tag tag-lead">Investigation Lead</span>
                        <p>High-priority starting points for investigative work.</p>
                    </div>
                    <div class="scoring-level">
                        <span class="level-tag tag-important">Important</span>
                        <p>Significant developments requiring your attention.</p>
                    </div>
                    <div class="scoring-level">
                        <span class="level-tag tag-background">Background</span>
                        <p>Contextual information archived for reference.</p>
                    </div>
                    <div class="scoring-level">
                        <span class="level-tag tag-noise">Noise</span>
                        <p>Irrelevant entries automatically deprioritized.</p>
                    </div>
                </div>

                <div class="about-grid" style="margin-top: 1.5rem;">
                    <div class="about-grid-item">
                        <strong>Deterministic Recipes</strong>
                        <p>Immediate scoring based on keyword weights and title boosting configured inside Seismo.</p>
                    </div>
                    <div class="about-grid-item">
                        <strong>Machine Learning</strong>
                        <p>Full ML classifier (e.g., XLM-RoBERTa) that trains on your manual labels for deep relevance.</p>
                    </div>
                </div>
                <p class="meta-text">API endpoints are documented in the <a href="https://github.com/hektopascal2026/magnitu" target="_blank" rel="noopener">Magnitu repository</a>.</p>
            </section>

            <!-- IV. Version History (Expanded) -->
            <section class="settings-section about-card">
                <h2>IV. Version History</h2>
                <div class="about-timeline">
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.1 – v0.3</strong> <span class="v-date">Jan – Feb 2026</span></div>
                        <div class="v-title">The Foundation</div>
                        <ul>
                            <li>Established the "Unified Feed" concept aggregating RSS and IMAP.</li>
                            <li>Initial SPARQL integration for Swiss (Fedlex) and EU (EUR-Lex) legislation.</li>
                            <li>Development of the core database schema for high-volume entry ingestion.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.4</strong> <span class="v-date">Feb – Mar 2026</span></div>
                        <div class="v-title">The Powerhouse Update</div>
                        <ul>
                            <li>Expanded geographic coverage to include Germany (recht.bund.de) and France (Légifrance).</li>
                            <li>Introduced Swiss case law (Jus) and the Parliament OData API.</li>
                            <li>Launch of the first Magnitu machine-learning integration.</li>
                            <li>Transitioned to a tabbed settings interface for complex configurations.</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry">
                        <div class="v-header"><strong>v0.4.3 – v0.4.4</strong> <span class="v-date">Apr 2026</span></div>
                        <div class="v-title">UX & Refinement</div>
                        <ul>
                            <li>Refactored email handling: senders became first-class subscriptions with domain-first matching.</li>
                            <li>Decentralized source management into dedicated screens (Feeds, Mail, Lex).</li>
                            <li>Improved timeline performance for large datasets (>100k rows).</li>
                        </ul>
                    </div>
                    <div class="about-timeline-entry current-version">
                        <div class="v-header"><strong>v0.5 (Current)</strong> <span class="v-date">Apr 2026</span></div>
                        <div class="v-title">Architectural Consolidation</div>
                        <ul>
                            <li><strong>Service-Oriented Core:</strong> Replaced procedural logic with lightweight controllers and repository patterns.</li>
                            <li><strong>Unified Pipeline:</strong> All fetching now runs under a master cron (<code>refresh_cron.php</code>).</li>
                            <li><strong>Security Hardening:</strong> Implementation of CSRF protection and a dormant session-auth layer.</li>
                            <li><strong>Clean API:</strong> Retired the "AI view" in favor of a stable, bearer-token-protected JSON/Markdown export API.</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- V. Operations & API -->
            <section class="settings-section about-card">
                <h2>V. Operations & Automation</h2>
                <div class="about-grid">
                    <div class="about-grid-item">
                        <strong>Refresh & Cron</strong>
                        <p>Timeline and Diagnostics share a single pipeline (<code>RefreshAllService</code>). Use <code>refresh_cron.php</code> for scheduled CLI updates.</p>
                    </div>
                    <div class="about-grid-item">
                        <strong>Retention</strong>
                        <p>Per-family cleanup policies ensure the database remains performant with dry-run safety.</p>
                    </div>
                </div>
                
                <h3 class="about-subheading">Export API</h3>
                <p>Seismo provides a dedicated Bearer-protected API for downstream automation (Raycast, n8n, LLMs):</p>
                <ul>
                    <li><code>?action=export_briefing</code> &mdash; Clean Markdown digest.</li>
                    <li><code>?action=export_entries</code> &mdash; Full JSON with score metadata.</li>
                </ul>
            </section>

            <!-- VI. Statistics -->
            <section class="settings-section about-card">
                <h2>VI. System Statistics</h2>
                <?php if ($aboutStats !== null && $scoreCounts !== null): ?>
                <div class="table-responsive">
                    <table class="styleguide-table">
                        <thead>
                            <tr>
                                <th>Database Family</th>
                                <th>Row Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Feed Definitions</td><td><?= e($fmt($aboutStats['feeds'])) ?></td></tr>
                            <tr><td>Timeline Items (Feeds)</td><td><?= e($fmt($aboutStats['feed_items'])) ?></td></tr>
                            <tr><td>Emails Ingested</td><td><?= e($fmt($aboutStats['emails'])) ?></td></tr>
                            <tr><td>Lex Items (Legislation)</td><td><?= e($fmt($aboutStats['lex_items'])) ?></td></tr>
                            <tr><td>Parliamentary Events</td><td><?= e($fmt($aboutStats['calendar_events'])) ?></td></tr>
                            <tr><td>Scraper Configurations</td><td><?= e($fmt($aboutStats['scraper_configs'])) ?></td></tr>
                            <tr class="stats-total-row">
                                <td><strong>Total Scores</strong></td>
                                <td><?= e($fmt($scoreCounts['total'])) ?> <span class="meta-text">(<?= e($fmt($scoreCounts['magnitu'])) ?> ML, <?= e($fmt($scoreCounts['recipe'])) ?> Recipe)</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="meta-text">Database statistics currently unavailable.</p>
                <?php endif; ?>
            </section>

            <!-- VII. Roadmap -->
            <section class="settings-section about-card">
                <h2>VII. Roadmap: Seismo Satellites</h2>
                <p>The current focus is the rollout of <strong>Seismo Satellites</strong>. This architecture allows topic-specific profiles (e.g., Security, Digital Policy) to run on their own lightweight instances while sharing the heavy ingestion infrastructure of a central <strong>Mothership</strong>.</p>
            </section>

            <footer class="about-footer">
                <p>Built with precision by <a href="https://hektopascal.org" target="_blank" rel="noopener">hektopascal.org</a>.</p>
                <p class="meta-text about-meta">Dev Detail: See <code>README-REORG.md</code> for internal architectural migration notes.</p>
            </footer>
        </main>
    </div>

    <!-- Minimal styles to support the new layout without breaking the theme -->
    <style>
        .about-modern-layout {
            max-width: 54rem !important; /* Slightly wider for the cards */
        }
        .about-card {
            background: #fff;
            border: 2px solid #000;
            padding: 1.5rem !important;
            margin-bottom: 2rem;
            box-shadow: 4px 4px 0 #000;
        }
        .about-card h2 {
            margin-top: 0 !important;
            border-bottom: 2px solid #000;
            padding-bottom: 0.5rem;
            font-size: 1.2rem;
        }
        .about-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 1rem;
        }
        .about-grid-item strong {
            display: block;
            margin-bottom: 0.25rem;
            font-size: 0.95rem;
        }
        .about-grid-item p {
            margin: 0 !important;
            font-size: 0.85rem;
            opacity: 0.85;
        }
        .about-scoring-levels {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .scoring-level {
            padding: 0.75rem;
            border: 1px solid #eee;
            background: #fafafa;
        }
        .level-tag {
            display: inline-block;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            border: 1px solid #000;
            margin-bottom: 0.5rem;
        }
        .tag-lead { background: #ffdada; }
        .tag-important { background: #fff4d1; }
        .tag-background { background: #e1f5fe; }
        .tag-noise { background: #f5f5f5; color: #999; }
        .scoring-level p {
            margin: 0 !important;
            font-size: 0.8rem;
        }
        .v-header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 0.2rem;
        }
        .v-date {
            font-size: 0.8rem;
            opacity: 0.6;
        }
        .v-title {
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
            color: var(--seismo-accent, #000);
        }
        .current-version {
            border-left-width: 6px !important;
            background-color: #fffdec !important;
        }
        .stats-total-row td {
            background-color: #ffffc5;
            border-top: 2px solid #000;
        }
        @media (max-width: 600px) {
            .about-grid, .about-scoring-levels {
                grid-template-columns: 1fr;
            }
        }
    </style>
</body>
</html>

