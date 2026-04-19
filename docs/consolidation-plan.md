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
2. **`entryTable()` is sacred.** Any query touching `feed_items`, `feeds`, `scraper_configs`, `emails` (unified), `sender_tags`, `email_subscriptions`, `lex_items`, `calendar_events` (Leg) **must** go through it. `entry_scores`, `magnitu_config`, `magnitu_labels` are always local.
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

- **Core fetchers:** RSS, Substack, Mail (with email schema unification migration), Scraper — each ported as a Core service (not a plugin). Each ships with repository `prune()`.
- **Plugins:** LexEu, LexLegifrance, RechtBund (as Jus variant), Parl MM, any other third-party adapter — each follows the Slice 2 template.
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

- **0.4 subscription-based email hiding is NOT ported.** v0.4's Magnitu responses filtered out emails whose sender address had `show_in_magnitu = 0` in `email_subscriptions`. v0.5 exposes all `emails` rows via `MagnituExportRepository::listEmailsSince()` regardless of subscription visibility. Re-adding the filter is a deliberate product decision (sender-level opt-out for the Magnitu pipeline) — file under Slice 6 if confirmed; do not smuggle it into a later slice.
- **`magnitu_status.version` contract drift.** 0.4 returned a monolithic `version` string baked from the running codebase. v0.5's `MagnituController::status()` returns `{"schema_version": <int>, "recipe_version": <int>}` instead, which is what 0.4's `sync.py` actually consumes. If the mothership tooling still reads `.version` as a single string, the consumer must be updated in the same push. Documented here so the next reviewer doesn't mistake it for an oversight.
- **`ScoringService::rescoreAll()` runs synchronously from the `magnitu_recipe` POST handler.** Up to ~2,000 rescores (500 per family × 4 families) fit inside a shared-host PHP timeout today; beyond that it needs to move to a cron worker. Filed as Slice 5b follow-up below rather than a warning to re-raise every review.
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
- **Retention settings ship as a standalone `?action=retention` page**, not a tab inside a combined Settings surface — the settings-tab architecture is Slice 6's concern. The page is linked from the Diagnostics top-bar and will graduate into a tab later.
- **Preview / actual consistency is enforced by construction.** Every family repo routes both `prune()` and `dryRunPrune()` through a private `buildPruneWhere()` that pulls the keep-fragment from `RetentionPredicates::forEntryType()`. The two queries cannot diverge modulo rows inserted between the two calls.
- **`MagnituConfigRepository` is deleted outright, not kept as a deprecated shim.** Every callsite in 0.5 has been renamed to `SystemConfigRepository`; no external consumer was relying on the old class name. `SystemConfigRepository::get()/set()` still falls back to the legacy `magnitu_config` table name during the transition window (deploy-code → run-migrate), documented to be removed in Slice 6.
- **`jus_banned_words` is stored under `lex:jus_banned_words`, not `plugin:jus_banned_words`.** It is a shared filter list, not a plugin block — routing it differently keeps `SystemConfigRepository::getAllPluginBlocks()` honest.
- **`EmailRepository` is a new retention-only repo.** Reads still go through `EntryRepository` / `MagnituExportRepository`; `EmailRepository` owns `prune()` and `dryRunPrune()` only. Resist folding all email SQL here without a consumer asking — "one SQL owner per concern" is load-bearing.

### Slice 6 — Admin / settings polish

- **Dashboard search performance (optional).** `EntryRepository::searchTimeline` uses `LIKE %term%` on `feed_items` (incl. `MEDIUMTEXT content`) — fine at current volume; if the host slows down, add a MySQL **FULLTEXT** index on `(title, description, content)` (and/or switch the feed leg of search to `MATCH … AGAINST`) — evaluate with real corpus sizes first.
- **Main feed page size (user setting).** Persist a default number of entries for `?action=index` (today `DashboardController` uses hardcoded `DEFAULT_LIMIT = 30`; `EntryRepository::MAX_LIMIT` stays the hard cap at 200). Store in `system_config` after Slice 5a, or interim key in `magnitu_config` if settings land before rename. Settings UI: numeric field + validation; dashboard reads saved default when `?limit=` is absent.
- Settings tabs split into clean partials (one concern each).
- Retention UI polish (family toggles, per-family overrides, "last pruned N rows on DATE" readout).
- Plugin diagnostics polish (test history, per-plugin last-N-runs view).
- Retire `ai_view` experiments or fold into one admin path.
- Styleguide kept as design source of truth.
- **Navigation drawer on the dashboard and per-source pages** (deferred from Slice 1): top-bar menu button reopened, drawer links to Feeds / Mail / Lex / Leg / Magnitu / Settings / About. Can land earlier if navigability pain surfaces before Slice 6 — in that case, split into a small "Slice 2.5 — navigation" and update this section to point at the slice it moved to. Do not re-add it mid-slice without naming the slice (`slice-scope-fidelity.mdc`).

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
- **`ai_view`** — keep as admin-only, retire, or fold into Magnitu UI? Decide before Slice 6.
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

- **Dashboard filter pills:** `EntryRepository::getFilterPillOptions()` runs three `SELECT DISTINCT` queries per request — acceptable for now; consider a ~1-minute cache (session or APCu) in Slice 6.
- **Scraper / feed_items sort churn:** `FeedItemRepository::upsertFeedItems()` keeps the existing `published_date` when `content_hash` is unchanged so scraper re-runs do not float stale pages to the top of the dashboard.
- **Diagnostics parity:** Core fetchers have per-id “Refresh now” on `?action=diagnostics` (same POST as plugins). A “Test fetch (no save)” path for RSS/scraper/mail is still deferred — not part of the `SourceFetcherInterface` test shape.
