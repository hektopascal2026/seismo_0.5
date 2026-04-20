# Seismo 0.5 — consolidation plan

Living document. Captures the architectural north star and the **order of work** when porting from 0.4. Update as decisions change.

## Goals (non-negotiable)

- Clean, documented, portable PHP app that **can be uploaded to any webspace** without editing paths or URLs.
- Preserve product achievements: **consistent entry cards**, **satellite / mothership split**, **Magnitu API contract**, early session-lock release for read-only pages.
- Remove procedural scars from 0.4 **without rewriting from scratch**: extract and tighten, layer by layer.
- Stay **zero-extra-dependency** where it matters (templates especially). Composer only for existing real needs (SimplePie, EasyRdf, etc.).

## Architectural direction

Lightweight OOP with **bounded classes**, not a full framework:

- **Controllers** — handle `$_GET` / `$_POST`, validate, delegate, render.
- **Repositories** — own SQL. Always use `entryTable()` for entry-source tables; scoring tables stay local.
- **Services / Fetchers** — external I/O (HTTP, SPARQL, IMAP). Return arrays / small value objects; do **not** write to the DB directly.
- **Bootstrap** — small: autoload, config, PDO, satellite/brand helpers, session.

### Repositories, views, formatters — the data/presentation split

Repositories return **raw, unescaped, unformatted** data. Views escape for HTML via `e()`. Formatters (added in Slice 5 alongside the export surface) consume the same raw arrays to produce Markdown or JSON for machine readers. Never bake presentation concerns into the repository layer — an HTML-escaped field in a repository return value is a tax on every non-HTML consumer (LLM briefings, JSON exports, CLI tools).

Rule: `core-plugin-architecture.mdc`, section "Repositories return raw data".

### Core vs Plugin split

Code is split into **Core** (things we control; stable by nature — RSS parsing, IMAP, scraping, scoring, Magnitu API, dashboard) and **Plugin** (third-party adapters: Fedlex, RechtBund, EU Lex SPARQL, Légifrance, Parlament.ch, …). Plugins implement `Seismo\Service\SourceFetcherInterface` and live in `src/Plugin/<Name>/`. `RefreshAllService` wraps every plugin in `try/catch` so one broken upstream never takes down the app.

**Persistence decision: Option B — plugins share existing family tables.** Plugins return DTOs; the runner writes them via the family repository (`LexItemRepository`, `CalendarEventRepository`, …). Plugin-specific fields live in the existing `metadata JSON` column. This preserves the polymorphic dashboard timeline (card layout), the stable Magnitu `entry_type` enum, and single-place `entryTable()` satellite wrapping.

**Configuration decision:** plugins share the existing family JSONs (`lex_config.json`, `calendar_config.json`); each plugin exposes a `getConfigKey()` pointing at its block.

**Failure surface decision:** plugin errors surface in Settings / diagnostics (extending the existing feed-diagnostics area and the 0.5 `?action=health` surface). No banner on the dashboard, no email alerts. `error_log` stays the ground truth for server logs.

Full rule: `.cursor/rules/core-plugin-architecture.mdc`.

### Fetcher output contract (planned — Slice 3 unified refresh / Core RSS)

RSS and scrapers vary wildly: some publishers ship full `<content:encoded>`, some only a teaser `<description>`, some almost nothing beyond `<title>` and `<link>`. **Views cannot fix bad upstream data at ingest time** — they only make legacy rows tolerable (`views/partials/dashboard_entry_loop.php` defensive link/body fallbacks).

When Core fetchers feed `RefreshAllService`, normalise **before** the repository persists:

1. **No title** → omit the item (do not insert).
2. **No navigable URL** for feed-like entries (not email) → omit — avoids dead dashboard cards.
3. **Empty body but non-empty description** → treat description as the body so cards always have expandable text when upstream provided a teaser.
4. **Optional later (not Slice 2):** per-feed setting *Attempt full-text extraction* — if the feed is title+link only, optionally pass the URL through the existing scraper/readability path to backfill body text. Lives with feed settings + retention, not the Lex plugin port.

### Guardrails to keep in Cursor rules

1. **Move off global procedural handlers.** `handleLexPage()` → `LexController::show()`; DB reads → `LexRepository`; HTTP → `LexFetcherService`.
2. `**entryTable()` is sacred.** Any query touching `feed_items`, `feeds`, `scraper_configs`, `emails` (unified), `sender_tags`, `email_subscriptions`, `lex_items`, `calendar_events` (Leg) **must** go through it. `entry_scores`, `magnitu_config`, `magnitu_labels` are always local.
3. **Decouple fetch from persist.** Fetchers return data; Repositories do `INSERT ... ON DUPLICATE KEY UPDATE`.
4. **Slim `config.php`.** Scoring belongs in a `ScoringService`. DDL belongs in a CLI migration, not on every request.
5. **Unify email schema.** Retire the older `emails` shape in favour of the `fetched_emails` structure (proper IMAP UID handling). Ship a one-time migration with a dry-run and clear backup guidance.
6. **One refresh pipeline.** Web `Refresh all` and `refresh_cron.php` call the **same** function (today they drift — cron misses the Leg fetch).
7. **Native PHP views stay.** No Twig/Blade/Plates in 0.5. Use partials + small view helpers. Escaping discipline via `htmlspecialchars()` everywhere.

## Execution strategy — vertical slices, not waterfall

A strict five-phase waterfall risks **nothing runnable** until late. Instead: stay within the target architecture while delivering **one thin end-to-end slice** per step.

### Slice 0 — Skeleton & health check

- `bootstrap.php`: UTC (`date_default_timezone_set('UTC')`), Composer vendor autoload if present, custom `Seismo\*` PSR-4 autoloader, `isSatellite()`, `entryTable()`, PDO singleton with `SET time_zone = '+00:00'`.
- Minimal class-based router in `index.php` (preserve read-only session-lock release).
- `migrate.php` CLI + `Seismo\Migration\MigrationRunner`: migration 17 applies `docs/db-schema.sql` (consolidated 0.4 schema, all `CREATE TABLE IF NOT EXISTS`). Idempotent on existing 0.4 databases; stamps `magnitu_config.schema_version`. Later migrations append new numbered classes in `src/Migration/`.
- One trivial route (e.g. `?action=health`) returning DB + version status.
- **Definition of done:** fresh empty DB + `config.local.php` + migrations applied (CLI: `php migrate.php`, or **no CLI:** `?action=migrate&key=…` with `SEISMO_MIGRATE_KEY` in config) creates all tables and sets schema 17; `?action=health` shows schema 17; existing live DB prints “Nothing to do” when already at 17.

### Slice 1 — Read-only dashboard

- `EntryRepository` — polymorphic reads, satellite-aware, **bounded** (every list method takes `$limit` / `$offset`, hardcoded cap at 200; no unbounded SELECTs), **raw** (no HTML-escaping in the repo layer), **UTC-clean** (all timestamps as `DateTimeImmutable` in UTC).
- `DashboardController` that lists entries using the existing `dashboard_entry_loop.php` partial unchanged (cards are protected). Controller applies `$limit`/`$offset` from `$_GET`.
- No writes yet. Satellite mode should work against a mothership DB without scraping.
- **Definition of done:** feed renders on both mothership and satellite; all card types look identical to 0.4; no query returns more than 200 rows regardless of URL params; no `htmlspecialchars` call in repository code.
- **Explicitly deferred from this slice** (reassigned to named slices after an unilateral drop in the first Slice 1 attempt — see `slice-scope-fidelity.mdc`):
- Search box (`?q=...`) → **Slice 1.5**. Read-only; no reason to wait for Slice 4.
- Favourites-view toggle (`?view=favourites`) → **Slice 1.5**. Read-only filter against `entry_favourites`.
- Tag filter pills (feed / email / substack categories, Lex pills, scraper pills, Leg pill) → **Slice 4**. Pills depend on fully ported entry sources + the sender_tags unification that Slice 4 already touches; doing them piecewise now would ship partial filters that break as each source ports.
- Per-card star buttons (render) → **Slice 1.5**; POST to `?action=toggle_favourite` → **Slice 3**. Render-only stars that 500 on click are worse UX than none, so they ship together with the toggle route.
- Top-bar "Refresh all" button and `?action=refresh_all` → **Slice 3** (unified refresh pipeline).
- Navigation drawer with links to other pages → **Slice 6** (admin/settings polish), unless navigability pain surfaces sooner as Slices 2–4 land pages that need to be reachable from the dashboard.

### Slice 1.5 — Dashboard filters (read-only) — **shipped (1.5b)**

- Search box: `EntryRepository::searchTimeline(string $q, int $limit, int $offset)`. Runs `LIKE` across title + description + content for feed_items, subject + body for emails, title + description for lex_items and calendar_events. Bounded + satellite-safe like Slice 1.
- Newest / Favourites view toggle: `EntryRepository::getFavouritesTimeline(int $limit, int $offset)` — **local** `entry_favourites` only (never wrapped in `entryTable()`).
- **1.5b (chosen):** per-card star buttons + `?action=toggle_favourite` → `EntryFavouriteRepository::toggle()` + `FavouriteController` (POST, redirect back with preserved query). Single-row writes to local `entry_favourites` only.
- **Definition of done:** `?q=` filters the merged timeline without regressions; `?view=favourites` shows only starred entries; star click toggles favourite state and reloads; no query exceeds 200 rows; no HTML in the repository layer.

### Slice 2 — Lex as the reference plugin port

- Introduce `Seismo\Service\SourceFetcherInterface`. **Shared HTTP / SPARQL client wrapper** (timeouts, user-agent, retry policy for HTTP plugins) is **deferred to Slice 3** — see Slice 3 bullet below; Fedlex uses `EasyRdf\Sparql\Client` directly inside `LexFedlexPlugin` for this slice only.
- Add `composer.json` pulling EasyRdf (SPARQL) and any other genuine vendor needs for Lex; Composer's `vendor/autoload.php` is already wired in Slice 0's bootstrap.
- **Pull-forward (documented here, not a deferral):** a **minimal** `PluginRegistry` (Fedlex only) and `PluginRunner` (Fedlex-only `runFedlex()` entry point) shipped in Slice 2 so the Lex refresh path is not a one-off; Slice 3 **generalises** them into the full registry list + `RefreshAllService` + CLI — see `README-REORG.md` Slice 2 and `.cursor/rules/slice-scope-fidelity.mdc` (pull-forwards).
- Implement `LexFedlexPlugin` (first plugin) + `LexItemRepository` (first family repo) + `LexController` + Lex page + Fedlex (`ch`) settings on the Lex page.
- Plugin writes nothing directly; controller/refresh handler calls plugin → repository.
- `LexItemRepository` exposes `prune()` from day one (repository contract, see `core-plugin-architecture.mdc`). Default policy: unlimited for `lex_items`.
- `LexItemRepository::upsertBatch()` is **transactional** (begin / commit / rollback on any row failure). Plugin batches go in whole or not at all.
- **Definition of done:** Lex list view and Fedlex refresh work end-to-end through the new plugin layering — template for every subsequent plugin and family repo; intentionally inserting a bad row in a test batch rolls the whole batch back and leaves `lex_items` untouched. Manual verification of the bad-row case is recorded in `README-REORG.md` (Slice 2).

### Slice 3 — Unified refresh pipeline, master cron, auth backbone, diagnostics — **shipped**

- **Generalised** the Slice 2 `PluginRegistry` + `PluginRunner` into `Seismo\Service\RefreshAllService` shared by web + CLI: `runAll(force)`, `runPlugin($id, force)`, `testPlugin($id, peek)`. Writes to a new `plugin_run_log` table (schema **v18**, migration `Migration002PluginRunLog`).
- **Master Cron pattern.** `refresh_cron.php` is the **only** cron entry the admin needs to register (e.g. `*/5 * * * * php /path/refresh_cron.php`). `RefreshAllService` consults each plugin's `getMinIntervalSeconds()` and skips plugins whose last `ok` row in `plugin_run_log` is fresher than that. **Throttle skips are stdout-only** (visible in cron mail; not persisted to `plugin_run_log` to avoid bloat). User-initiated refresh paths pass `force=true` and bypass the throttle. For v0.5 the throttles are **hardcoded** in each plugin (Fedlex 4h, ParlCh 4h); a Settings UI for tuning is not in scope.
- **Shared HTTP client helper.** `Seismo\Service\Http\BaseClient` + `Response` + `HttpClientException` (timeouts, custom User-Agent, single retry on 429/503; supports both cURL and PHP streams). ParlChPlugin uses it; future HTTP plugins (LexEu, RechtBund, Légifrance OAuth, …) inherit. Fedlex still uses `EasyRdf\Sparql\Client` directly — SPARQL has its own session shape; revisit only if duplication shows up.
- **Leg** ported (ParlCh plugin under `src/Plugin/ParlCh/`) with `CalendarEventRepository` (transactional `upsertBatch`, satellite guards) and `CalendarConfigStore` (`calendar_config.json` mutable per deploy, `calendar_config.example.json` committed). `?action=leg` and legacy `?action=calendar` both resolve to `LegController::show`.
- **Auth backbone (dormant by default).** `Seismo\Http\AuthGate`, `Seismo\Controller\AuthController`, `views/login.php`, `SEISMO_ADMIN_PASSWORD_HASH` switch in `config.local.php.example`. No behaviour change unless the admin opts in. See `auth-dormant-by-default.mdc`. `HealthController` degrades when auth is enabled and the visitor isn't logged in (DB status only, no PHP/MySQL versions).
- **CSRF on every mutating POST.** `Seismo\Http\CsrfToken` (single rotating session-bound token, single-use rotation on success). Wired into: `toggle_favourite`, `refresh_fedlex`, `save_lex_ch`, `refresh_parl_ch`, `save_leg_parl_ch`, `refresh_all`, `refresh_plugin`, `plugin_test`, `login`, `logout`. Tokens render as `<?= CsrfToken::field() ?>`. Tokens are still emitted (and harmless) when auth is dormant.
- **Diagnostics page.** New `?action=diagnostics` (under AuthGate). Lists every registered plugin with its latest `plugin_run_log` row, throttle interval, and "next allowed run" timestamp. Per-plugin **Refresh now** (force=true) and **Test fetch (no save)** buttons; master **Refresh all now** button. Test result peek (first 5 rows) returned as a one-shot session flash.
- **Definition of done — met.** `refresh_cron.php` and `?action=refresh_all` execute the same `RefreshAllService::runAll()`; Leg is included in both; a failing plugin logs to `plugin_run_log` and shows red in diagnostics while the rest of the pipeline keeps running; `SEISMO_ADMIN_PASSWORD_HASH` toggles login on/off; plugin **Test fetch** button shows first 5 items of a dry-run fetch; **all mutating POSTs require a valid CSRF token** regardless of auth state.

### Slice 4 — Remaining entry sources — **shipped**

- **Core fetchers:** RSS, Substack, Mail (with email schema unification migration), Scraper, and **`core:parl_press`** (Parlament Medien → `feed_items`; not Lex) — each ported as a Core service (not a Lex/Leg plugin). Each ships with repository `prune()` where applicable.
- **Swiss Parliament press (“Parl MM”) — Core, not Lex:** treated **semantically as a news feed**. Data lands in **`feed_items`** via `feeds.source_type = parl_press` and Core `ParlPressFetchService` / `CoreRunner::core:parl_press`. Optional `feeds.category` (e.g. `parl_mm`) supports filters. **No** `lex_items` row family, **no** Lex plugin, **no** Leg plugin — product decision “Option 2”; see `.cursor/rules/core-plugin-architecture.mdc`.
- **Lex plugins:** LexEu, LexLegifrance, LexRechtBund (`recht.bund` RSS) — each follows the Slice 2 template (`SourceFetcherInterface` → `lex_items`).
- Unify email schema under `emails` built from today’s `fetched_emails` structure; provide migration script.
- **Tag filter pills on the dashboard** (deferred from Slice 1): feed-category pills, email-tag pills, substack-category pills, Lex source pills, scraper-source pills, Leg toggle. Ships here rather than piecemeal so all pills reflect fully ported sources and the unified `sender_tags`/email-tags surface.

### Slice 5 — Magnitu boundary + machine-readable export surface — **shipped**

- `MagnituConfigRepository`, `ScoringService` (out of `config.php`).
- API controllers (`magnitu_entries`, `magnitu_scores`, `magnitu_recipe`, `magnitu_labels`, `magnitu_status`).
- Satellite keys, Leg API exclusion stays intentional until we decide otherwise.
- **Read-only export key.** Second API key alongside the Magnitu one: `export:api_key` (read-only; can pull entries/briefings, cannot write scores/labels). Two-key model for v0.5; a scopes table is graduation-path, not now.
- **Export endpoints.** `?action=export_briefing&since=<iso8601>&format=markdown|json` and `?action=export_entries&since_id=<id>&format=json`. Filters by timestamp or id so the client tracks its own "last seen" state (stateless — Option A). Bearer-token auth, read-only key only.
- **Formatters.** `Seismo\Formatter\MarkdownBriefingFormatter`, `Seismo\Formatter\JsonExportFormatter`. Both consume raw repository output; neither has SQL or HTML. A view or export controller picks one and renders it with the appropriate `Content-Type`.
- **Definition of done — met.** An external LLM/automation script with the export key can pull the last N days of top-scored entries as Markdown, on demand, with zero write privileges and no server-side state beyond the key itself.

**Scope-fidelity notes for Slice 5 (per `slice-scope-fidelity.mdc`):**

- **0.4 subscription-based email hiding is NOT ported.** v0.4's Magnitu responses filtered out emails whose sender address had `show_in_magnitu = 0` in `email_subscriptions`. v0.5 exposes all `emails` rows via `MagnituExportRepository::listEmailsSince()` regardless of subscription visibility. Re-adding the filter is a deliberate product decision (sender-level opt-out for the Magnitu pipeline) — track explicitly in a future slice if confirmed; do not smuggle it in without a plan entry.
- `**magnitu_status.version` contract drift.** 0.4 returned a monolithic `version` string baked from the running codebase. v0.5's `MagnituController::status()` returns `{"schema_version": <int>, "recipe_version": <int>}` instead, which is what 0.4's `sync.py` actually consumes. If the mothership tooling still reads `.version` as a single string, the consumer must be updated in the same push. Documented here so the next reviewer doesn't mistake it for an oversight.
- `**ScoringService::rescoreAll()` runs synchronously from the `magnitu_recipe` POST handler.** Up to ~2,000 rescores (500 per family × 4 families) fit inside a shared-host PHP timeout today; beyond that it needs to move to a cron worker. Filed as Slice 5b follow-up below rather than a warning to re-raise every review.
- **Fetch/persist boundary fixed post-review.** The initial Slice 5 submission had `ScoringService` issuing its own `SELECT` queries against entry-source tables (first Gemini review flagged this as a **FAIL**). Fixed in-session by moving every unscored-row lookup into `EntryScoreRepository` as `getUnscoredFeedItems() / getUnscoredLexItems() / getUnscoredEmails() / getUnscoredCalendarEvents()`, each honouring `entryTable()` + a repo-local `MAX_UNSCORED_LIMIT = 500`. `ScoringService` no longer takes a `PDO` — it depends only on `EntryScoreRepository` and orchestrates `RecipeScorer::score()` → `upsertRecipeScore()`. Second Gemini review: PASS.

### Slice 5b — Async recipe rescoring (deferred follow-up)

- `ScoringService::rescoreAll()` currently runs inline inside the `magnitu_recipe` POST handler (Slice 5). At 500-per-family × 4 families the upper bound is ~2,000 rescores per request, which fits in a 60 s shared-host timeout today.
- If corpus growth, shared-host timeouts, or client impatience ever push that over the edge, move rescoring to a cron-style queue: the POST handler bumps `recipe_version` + flips an `entry_scores` "dirty" flag (or clears the `model_version` for non-Magnitu rows), and a `RescoreWorker` called from `refresh_cron.php` drains batches between plugin runs.
- Not in scope while the synchronous path measurably works; listed here so the path is pre-agreed and not re-litigated in a review.

### Slice 5a — Config unification + retention service — **shipped**

- Rename `magnitu_config` table → `system_config` (breaking migration; documented, reversible in one step).
- Fold `lex_config.json` and `calendar_config.json` contents into `system_config` rows keyed `plugin:<identifier>`. Retire the JSON files.
- `Seismo\Service\RetentionService` composes keep-predicates from settings and calls each family repo's `prune()` at the end of `refresh_cron.php`. Default policy: `feed_items` 180d, `emails` 180d, `lex_items` unlimited, `calendar_events` unlimited. Manually-labelled / favourited / high-scored rows always kept.
- Settings tab: retention days per family, "dry run" preview showing how many rows would be deleted.
- **Definition of done:** no JSON config files remain; `php migrate.php` rebuilds a system_config table from the old layout; cron prunes feeds+emails older than 180 days while preserving protected rows; preview matches the real run count.

**Scope-fidelity notes for Slice 5a (per `slice-scope-fidelity.mdc`):**

- **JSON sidecars are renamed, not deleted.** Migration 005 renames `lex_config.json` → `lex_config.json.migrated-v21` (same for calendar) so the admin keeps an on-disk sample to diff against the folded rows. Deletion is left to the admin; not speculative.
- **Retention UI (state at 5a ship):** standalone `?action=retention` page, linked from Diagnostics. **Slice 6** folds this into `?action=settings&tab=retention`; GET `?action=retention` redirects there.
- **Preview / actual consistency is enforced by construction.** Every family repo routes both `prune()` and `dryRunPrune()` through a private `buildPruneWhere()` that pulls the keep-fragment from `RetentionPredicates::forEntryType()`. The two queries cannot diverge modulo rows inserted between the two calls.
- `**MagnituConfigRepository` is deleted outright, not kept as a deprecated shim.** Every callsite in 0.5 has been renamed to `SystemConfigRepository`. The brief deploy-gap fallback from `system_config` to `magnitu_config` in `SystemConfigRepository::get()/set()` existed until **Slice 6**, which removed it — only `system_config` is used now (schema ≥ 21).
- `**jus_banned_words` is stored under `lex:jus_banned_words`, not `plugin:jus_banned_words`.** It is a shared filter list, not a plugin block — routing it differently keeps `SystemConfigRepository::getAllPluginBlocks()` honest.
- `**EmailRepository` is a new retention-only repo.** Reads still go through `EntryRepository` / `MagnituExportRepository`; `EmailRepository` owns `prune()` and `dryRunPrune()` only. Resist folding all email SQL here without a consumer asking — "one SQL owner per concern" is load-bearing.

### Slice 6 — Admin / settings polish — **shipped**

- **Unified settings.** `?action=settings` with tabs **General** (default dashboard page size → `system_config` key `ui:dashboard_limit`) and **Retention** (embedded `views/partials/retention_panel.php`). POST `?action=settings_save` (CSRF).
- **Retention URL.** GET `?action=retention` redirects to `?action=settings&tab=retention`; POST handlers unchanged. Standalone `views/retention.php` removed.
- **Navigation drawer** (`views/partials/site_header.php`) on dashboard, Lex, Leg, Diagnostics, Settings, Styleguide — links use `getBasePath()`. No separate Feeds/Mail/About routes in 0.5 yet.
- **View timezone** — `SEISMO_VIEW_TIMEZONE` (default `Europe/Zurich`); `seismo_view_timezone()`; day headings + `seismo_format_utc()` use it.
- `**SystemConfigRepository`** — legacy `magnitu_config` fallback **removed** (requires `system_config` / schema ≥ 21). `MigrationRunner::getCurrentVersion()` probes `magnitu_config` one last time and throws a clear RuntimeException if a pre-v21 schema is detected, so a deploy that skips Slice 5a fails loudly instead of re-running migrations against a populated database.
- **Diagnostics** — last **8** runs per core/plugin from `plugin_run_log` in a single batch query (`PluginRunLogRepository::recentForPlugins()` — one round-trip via per-id `UNION ALL` legs); renders through `views/partials/plugin_recent_runs.php`.
- **Filter pills** — ~60s session cache for the three `SELECT DISTINCT` queries lives in `DashboardController` (not the repository, to keep `EntryRepository` SQL-only). Falls through to the raw repo call when the session isn't active.
- `**?action=styleguide`** — minimal design reference page.
- **Definition of done — met.** Gemini spot-check PASS (2026) plus follow-up remediation: filter-pill cache moved out of the repository, diagnostics batch query, `MigrationRunner` legacy-schema guard, `?action=retention` dropped from the readonly-session list (pure redirect since this slice), diagnostics `<details>` block extracted to a partial, `RetentionService::DEFAULT_POLICIES` adopted as SSoT for `$families` in `SettingsController`.

**Deferred / optional (not Slice 6):**

- **Dashboard search:** optional FULLTEXT / `MATCH … AGAINST` when corpus grows — still in play.
- **Retention UI:** family toggles, per-family overrides, “last pruned on DATE” readout — not built.
- `**ai_view`** — no 0.5 code to retire; revisit if a route appears.

### Slice 7 — Magnitu settings tab (admin UI for the Magnitu contract) — **shipped**

Port of 0.4's `Settings → Magnitu` tab. Slice 5 shipped the HTTP contract but left the admin surface unported, so a fresh 0.5 instance had no browser-only path to provision the `api_key` that `BearerAuth::guardMagnitu()` reads. Slice 7 closes that gap.

- `**MagnituAdminController`** (new) — session-auth + CSRF, strictly separate from `MagnituController` (which is Bearer-only). Three POST handlers:
  - `?action=settings_save_magnitu` — writes `alert_threshold` (clamped 0.0–1.0) and `sort_by_relevance` to `system_config`.
  - `?action=settings_regenerate_magnitu_key` — mints `bin2hex(random_bytes(16))` and upserts `system_config.api_key`.
  - `?action=settings_clear_magnitu_scores` — `EntryScoreRepository::clearAll()` + reset `recipe_json` / `recipe_version` / `last_sync_at` rows. The "Danger Zone" action.
- `**views/partials/settings_magnitu.php**` — five sections matching 0.4's layout: API key row (click-to-copy + Regenerate), Seismo API URL row (click-to-copy), 3-tile score counts + last-sync line + optional Connected Model block, Scoring Settings form (alert_threshold + sort_by_relevance), Danger Zone.
- `**SettingsController::show()**` — accepts `tab=magnitu`; loads the nine-key `$magnituConfig` slice of `system_config`, the score-source-split `$magnituScoreStats` from `EntryScoreRepository::getScoreCounts()`, and a derived `$seismoApiUrl` (`scheme://host` + `getBasePath()` + `/index.php`, honouring `HTTP_X_FORWARDED_PROTO` for shared hosts like hektopascal).
- **Routes** (`index.php`) — three POSTs registered with `readOnly=false`; GET tab is served by the existing `settings` route, so `READONLY_KEEP_SESSION_FOR_CSRF` already covers it.
- **Docs + rule** — `magnitu-integration.mdc` updated: "has not been ported yet" banner replaced with the shipped-slice chronology; the Key Tables entry for `system_config` now names Slice 7 / `MagnituAdminController`; `model_meta` config keys added to the keys list.

**Explicitly out of Slice 7**:

- Wiring `alert_threshold` into the dashboard / calendar and `sort_by_relevance` into timeline sort. The inputs persist, the consumers don't exist yet — tracked as a follow-up below.
- Hashing the API key at rest. `BearerAuth::verifyMagnituKey` expects the raw value via `hash_equals`, matching 0.4; changing that model is its own slice.
- Per-profile / per-satellite key management UI. Each satellite already keeps its own `system_config`; this tab manages the local instance's key, which is all the rule requires.
- Porting `views/magnitu.php` (the "Magnitu highlights" standalone feed page). It's a feature view, not a settings page, and needs the dashboard to read `alert_threshold` first to be meaningful.

**Tracked follow-ups** (promoted to **Slice 7a** — scope below):

- Wire `alert_threshold` into the dashboard's "alert" highlight + the Leg badge.
- Wire `sort_by_relevance` into `DashboardController` / `EntryRepository` ordering.
- Port `views/magnitu.php` once the above wiring lands.

### Slice 7a — Wire Magnitu knobs into the dashboard

Closes the Slice 7 follow-ups. Slice 7 persists `alert_threshold` and `sort_by_relevance` but no consumer reads them yet, so the admin is tuning dials nothing is attached to. Slice 7a attaches the wires.

- `**alert_threshold` consumer.** `DashboardController` reads `alert_threshold` from `SystemConfigRepository` (single call, cached for the request). `EntryRepository` joins `entry_scores.relevance_score` into the timeline rows it already returns (still raw, still bounded). `views/partials/dashboard_entry_loop.php` renders an "alert" badge on cards where `relevance_score >= alert_threshold`. Same badge rendered on Leg cards in `views/leg.php`. No new SQL outside the repository.
- `**sort_by_relevance` consumer.** New default sort mode for the main timeline: when `sort_by_relevance = 1`, `EntryRepository::getTimeline()` orders by `relevance_score DESC, COALESCE(published_date, created_at) DESC`; otherwise unchanged. **Default is date-first** — relevance-first is opt-in via the Magnitu settings tab. No per-request `?sort=` parameter (keeps cacheability simple, avoids a URL surface we'd have to support forever).
- **Port `views/magnitu.php`.** Standalone "Magnitu highlights" page at `?action=magnitu` — entries with a Magnitu score ≥ `alert_threshold`, newest first. Reuses `dashboard_entry_loop.php` (card consistency is non-negotiable). Linked from the nav drawer. Session-auth guarded like the rest of the UI.
- **Out of scope:** a settings toggle for "hide recipe-only scores on the highlights page" (product decision, not an architectural one); any change to the Magnitu API response shape (Slice 5 contract stays frozen).
- **Definition of done:** setting `alert_threshold = 0.7` + saving immediately surfaces an "alert" badge on qualifying cards on the next dashboard load; flipping `sort_by_relevance` reorders the timeline without a per-request URL param; `?action=magnitu` renders the highlights feed; no query exceeds the `MAX_LIMIT` cap; no HTML-escaping in the repository layer.

### Slice 8 — Module-owned source admin (Feeds / Scraper / Mail)

Port 0.4.4's **Module-owned management UI** pattern. Each content module keeps its own admin surface on its own page via an `Items | <thing>` toggle — **not** buried under Settings. Settings stays reserved for genuinely global state (Magnitu, Retention, UI defaults).

**Load-bearing reason — do not drift.** 0.4.4's release notes explicitly moved away from a monolithic Settings page because source admin belongs next to the source's items. Reverting that would be a UX regression. Anti-pattern recorded in `feature-development-architecture.mdc`.

**Three modules, same pattern:**

- `**?action=feeds`** — existing RSS / Substack items view gains an **Items | Feeds** toggle (`?action=feeds&view=sources`). New `FeedRepository` (SQL only, `entryTable('feeds')`) owns list/upsert/delete of feed rows. Controller orchestrates; view reuses dashboard card styling for consistency.
- `**?action=scraper`** — scraped-items view gains an **Items | Sources** toggle. New `ScraperConfigRepository` (SQL only, `entryTable('scraper_configs')`). Same shape as Feeds.
- `**?action=mail`** — email timeline gains an **Items | Subscriptions** toggle. New `**EmailSubscriptionRepository`** (SQL only, `entryTable('email_subscriptions')`) with domain-first matching (`@example.com`), per-sender override, one-click unsubscribe action, and `show_in_magnitu` toggle exposed on the row.

**Load-bearing — do NOT target `sender_tags`.** `sender_tags` is the legacy (pre-0.4.3) data model. The first-class table for mail admin is `**email_subscriptions`** (domain-first, `show_in_magnitu` flag, one-click unsubscribe). The dashboard filter pills continue reading `sender_tags` for backwards-compat; migrating pill rendering to `email_subscriptions` is **explicitly out of scope** — separate slice if confirmed. Building the new UI around `sender_tags` writes code for a deprecated model.

**Cross-cutting:**

- All mutating POSTs: `CsrfToken` + session auth (AuthGate) + satellite-refuse at the repository level (defence in depth per `core-plugin-architecture.mdc`).
- Routes registered in `index.php`; read-only GETs flagged; `READONLY_KEEP_SESSION_FOR_CSRF` updated if the toggled views render forms on GET.
- Per-module "Refresh now" lives on Diagnostics (already shipped Slice 3/6) — not duplicated onto the new admin tabs.

**Explicitly out of Slice 8:**

- Re-adding `email_subscriptions.show_in_magnitu` as a filter on the Magnitu API response. That's the 0.4 behaviour Slice 5 deliberately did not port; re-enabling it is a product decision with its own plan entry.
- Migrating dashboard filter pills off `sender_tags`.
- A Settings tab for "default feed refresh interval" or similar — throttles stay hardcoded per `core-plugin-architecture.mdc` until we observe real pain.
- OPML import / export, bulk CSV feed upload. Single-row CRUD only.

**Definition of done:** from a fresh browser session, an admin can land on `?action=feeds`, `?action=scraper`, or `?action=mail`, switch to the management view via the inline toggle, and add / edit / delete a row without leaving that page. Settings page carries **no** source-management links. Repository tests for `EmailSubscriptionRepository` prove domain-first matching (`@example.com` matches `alice@example.com`) and satellite-write refusal.

### Slice 9 — Refresh button, About page, setup-wizard prep, `ai_view` retirement

The "last-mile polish" slice. Closes three small ergonomics gaps (dashboard refresh, in-app About, AI-view forwarding note) and puts the **defensive** setup wizard on paper so the first-run experience on shared hosts is honest.

**1. Dashboard refresh button.** Top-bar button on `?action=index` that POSTs to the existing `?action=refresh_all` (Slice 3). CSRF-guarded; ~15 lines of view + one router entry. Deferred from Slice 1 since Slice 3 onwards.

**2. `views/about.php` + `?action=about` route.** User-facing product description (what Seismo does, source types, Magnitu / Lex / Leg explanations, screenshots placeholder). Per `documentation-strategy.mdc`: one polished pass, user-language not developer-language, touched **only now** that the feature set has stabilised.

**3. `ai_view` retirement with forwarding note.** No 0.5 `ai_view` route was ever ported, so "retirement" is primarily documentation:

- In `views/about.php`, add an explicit paragraph: *"The 0.4 AI view has been replaced by the read-only export API — see `?action=export_briefing` (Bearer key `export:api_key`) for Markdown digests consumable by any LLM / automation client."* Do not delete the concept silently.
- Close the `ai_view` bullet in this file's **Open decisions** section.

**4. Setup-wizard prep (defensive, copy-paste fallback).** Not the full wizard — that graduates in a later milestone — but the **hard architectural constraint** every wizard iteration must respect, recorded now while the decision is fresh:

- **Primary path:** wizard prompts for DB credentials + optional admin password + `SEISMO_MIGRATE_KEY`, generates a complete `config.local.php` body, attempts `@file_put_contents(__DIR__ . '/config.local.php', …)`.
- **Defensive fallback.** If `is_writable(__DIR__)` is false (common on shared hosts where the PHP user ≠ file owner) **or** the write returns false, the wizard renders a **Copy & Paste** screen:
  - Full PHP block in a styled `<pre>`, with a "Copy" button.
  - Plain-language instruction: *"Paste into `config.local.php` at the root of your Seismo install via your hosting File Manager / SFTP."*
  - Next-step URL: `?action=health` to verify the paste took.
- **Never** `chmod 0777`, never suggest it, never write to `/tmp` as a workaround. Failure is loud and guided, not silent.
- **Per-step verification.** After DB credentials are supplied, the wizard calls `getDbConnection()` once and shows a green/red status before letting the admin continue. Same posture as `?action=health`.
- Findings that surface during this slice (new required extensions, writable-path edge cases, server-config quirks) land in `docs/setup-wizard-notes.md` so they don't have to be rediscovered.

**Explicitly out of Slice 9:**

- The full setup wizard UI (multi-step form, progress bar). Slice 9 fixes the **invariants** (write-defensive + copy-paste fallback + `?action=health` verification); the multi-step UX is a later milestone.
- Reintroducing any `ai_view`-style HTML page. Export API is the supported replacement.
- README.md final polish. That's its own pass per `documentation-strategy.mdc`.

**Definition of done:** dashboard has a working Refresh button (CSRF-guarded, redirects back); `?action=about` renders a human-readable product description that names the export API as the AI-view successor; a dry run of the wizard-stub on a host with `is_writable(__DIR__) === false` produces the copy-paste screen and never silently fails; `docs/setup-wizard-notes.md` gains at least one entry from live testing.

**Consolidation arc.** Slices **7a → 8 → 9** are the remaining numbered work for 0.5. No further numbered slices are planned for recipe-scoring polish in this document; sophisticated scoring belongs in **Magnitu** (the ML companion). The PHP `RecipeScorer` stays the cheap deterministic fallback — good enough to sort the dashboard until Magnitu overwrites scores.

## Portability checklist (applies to every slice)

- [ ] No hardcoded domains or absolute URL prefixes except intentional (`SEISMO_MOTHERSHIP_URL`, OAuth, external APIs).
- [ ] All internal links and redirects use `getBasePath()`.
- [ ] Filesystem paths via `__DIR__` only; downloaded configs flag that they embed the current server’s paths.
- [ ] New tables and ENUM widenings live in `migrate.php`, not request-time.
- [ ] Cron changes mirrored in `RefreshAllService` (or equivalent).
- [ ] Setup-wizard notes updated with any new requirement (extensions, writable paths, env vars).
- [ ] No naive timestamps — all `DateTimeImmutable` constructed with explicit UTC; all ISO-8601 output includes `Z` / `+00:00`.
- [ ] No list-returning repository method without `$limit` / `$offset`.
- [ ] No `upsertBatch` / multi-row write method without a transaction wrapper.
- [ ] No 0.4 feature dropped from the slice's scope without either (a) user confirmation in the same turn, or (b) a numbered slice entry added to this file. See `.cursor/rules/slice-scope-fidelity.mdc`.

## Views

Stay with **native PHP templates** in 0.5. Keep:

- `views/partials/dashboard_entry_loop.php` — unchanged behaviourally; any change reviewed per entry type.
- Small helper functions (e.g. `e()` for `htmlspecialchars()`) rather than an engine.
- Per-source pages remain plain PHP — same philosophy, same deployability.

Revisit a templating engine only if real pain emerges (duplicated markup, escaping bugs). Not preemptively.

## Open decisions

- **Per-feed full-text backfill** — when to add "readability" / scraper fetch for thin RSS items (see Fetcher output contract). Product/settings decision once Slice 3 feed settings exist.
- **Email schema unification** — exact column mapping from `emails` → unified structure. Needs a migration draft before Slice 4.
- **`ai_view`** — resolved in **Slice 9**: not ported. `views/about.php` points users to the Slice 5 export API (`?action=export_briefing`, `export:api_key`) as the official replacement.
- **Magnitu Leg API** — when (or whether) to lift the `calendar_event` exclusion. Product decision, not technical.

## Machine-readable export — forward-compat shape

Seismo's job isn't to generate briefings. It's to be a clean, machine-readable data provider that external tools (LLM scripts, n8n, cron + curl, ChatGPT, Raycast, …) can pull from. Three decisions that shape how we build toward that without bloating the app:

1. **Repositories stay raw.** Repository returns are the common substrate for HTML views **and** future formatters. No HTML in the repository layer, ever. Enforced by the rule in `core-plugin-architecture.mdc`.
2. **Stateless export.** The export endpoint does not track "already briefed" state. The client passes `?since=<timestamp>` or `?since_id=<id>` and Seismo filters. If multiple consumers ever need to coordinate, we add a tags/watermarks table then — Option B in the review was explicitly not scoped for v0.5.
3. **Read-only API key.** Separate key from the Magnitu API key so a briefing script can't accidentally (or maliciously) write scores. Two-key model for v0.5.

These all land in Slice 5. None of them require code today — just discipline.

## Decisions settled after external review (2026-04-19)

Recorded here so they don't get re-litigated:

- **Retention:** `feed_items` and `emails` prune at **180 days**; `lex_items` and `calendar_events` never prune. Favourites, high-Magnitu-scored rows, and manually labelled rows are always kept. User-overridable per family. Dry-run preview mandatory.
- **Auth:** native session auth built into 0.5 as a **dormant backbone**. Zero behaviour change unless `SEISMO_ADMIN_PASSWORD_HASH` is set in `config.local.php`. Switchable in one line.
- **Circuit breaker:** **not** building one for 0.5. `try/catch` + `plugin_run_log` is the contract. Revisit only if production shows a plugin flapping enough to matter.
- **Run log table:** single `plugin_run_log` table for plugin invocations. Non-plugin errors stay in PHP's `error_log()` — no parallel `system_logs` soup.
- **Config unification:** rename `magnitu_config` → `system_config` in Slice 5a; plugin configs move from JSON files into `system_config` rows keyed `plugin:<identifier>`. Breaking but one-shot migration.
- **Plugin dry-run:** no interface change needed. `fetch()` is already pure-function by contract (plugins can't touch the DB). The diagnostics "Test" button just calls `fetch()` and renders the first N items without invoking a repository.
- **Machine-readable export:** three commitments for Slice 5 — repositories return raw data (rule enforced from Slice 1), export is stateless (client passes `?since`), and a dedicated read-only API key keeps briefing scripts blast-radius-small. Stateful "already briefed" tracking is out of scope; we'd add it if a second consumer ever needs to coordinate, not speculatively.
- **Shared-host hardening (a round of four):** UTC everywhere (PHP + MariaDB session pinned); every list-returning repo method is bounded (`$limit`/`$offset`, hardcoded max 200); every `upsertBatch` wraps a transaction with rollback-on-any-row-failure; Composer `vendor/autoload.php` is included by `bootstrap.php` before our own autoloader so plugins bringing vendor deps (EasyRdf, etc.) don't crash at startup.

## Tracked follow-ups (post–Slice 4 hardening)

- **Dashboard filter pills:** ~~`EntryRepository::getFilterPillOptions()` runs three `SELECT DISTINCT` queries per request~~ — **Slice 6** adds a ~60s session cache for the merged options array. Cache lives in `DashboardController::getFilterPillOptionsCached()`; the repository method is still pure-SQL.
- **Scraper / feed_items sort churn:** `FeedItemRepository::upsertFeedItems()` keeps the existing `published_date` when `content_hash` is unchanged so scraper re-runs do not float stale pages to the top of the dashboard.
- **Diagnostics parity:** Core fetchers have per-id “Refresh now” on `?action=diagnostics` (same POST as plugins). A “Test fetch (no save)” path for RSS/scraper/mail is still deferred — not part of the `SourceFetcherInterface` test shape.

## Open Decisions / Future Polish

- **Title Boosting (Fallback Scorer):** Currently, title and content are concatenated before scoring. Future enhancement: tokenize separately and apply a `scoring:title_weight` multiplier (e.g., 2.0) to title matches.
- **Exact Phrase Matching:** If the recipe engine ever needs to match multi-word phrases, do not build a cross-language n-gram tokenizer. Use native PHP `stripos()` or a simple word-boundary regex (`\b(phrase)\b`) as a cheap, stateless escape hatch.
