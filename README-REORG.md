# Seismo 0.5 — reorganisation log

Technical companion to `README.md`, written **live** during the 0.4 → 0.5 consolidation. Explains, slice by slice, what moved, why, and how the new wiring works. The audience is anyone (including future-me) who knows 0.4 and needs to find where something went in 0.5.

- Entries are **newest on top**.
- Every entry follows the same four-part shape: **Why**, **What moved**, **New wiring**, **Gotchas**.
- References use **file paths**, not line numbers (they drift).
- Companion doc for users (in-app about page, `views/about.php`) is left alone until the consolidation is done — see `.cursor/rules/documentation-strategy.mdc`.

---

## Correction 2026-04-19 — Slice 1 scope drop was unilateral

**Why this entry exists.** The Slice 1 commit (`8458dda`) dropped four 0.4 affordances from the delivered scope — search box, tag filter pills, favourites view toggle, navigation drawer — and labelled them "deliberately out of scope — they come back in later slices" in the commit message. No slice was named for any of them, `docs/consolidation-plan.md` was not updated, and the user was not asked. The refresh button was also dropped but that one belongs to Slice 3 by the existing plan, so it isn't part of this correction.

This is a process failure, not an architectural one. Every individual decision was locally defensible (Slice 1's DoD only names the cards); the cumulative effect is scope erosion hidden in a commit message. Recording it here so the failure mode is visible rather than buried.

**What changed in response.**

- New rule: `.cursor/rules/slice-scope-fidelity.mdc`. Before collapsing any 0.4 feature out of a slice's delivered scope, the agent must either confirm with the user in the same turn, or append an explicit entry to `docs/consolidation-plan.md` naming the numbered slice that will carry it. "Later slices" without a number is explicitly rejected.
- `docs/consolidation-plan.md` Slice 1 now lists every deferred affordance with a target slice:
 - Search box → Slice 1.5
 - Favourites-view toggle → Slice 1.5
 - Per-card star buttons (render) → Slice 1.5; POST route → Slice 3 (or bundled into 1.5 as variant 1.5b, default)
 - Tag filter pills → Slice 4
 - Top-bar Refresh button → Slice 3 (no change; was always this)
 - Navigation drawer → Slice 6, with an escape hatch to land earlier as "Slice 2.5 — navigation" if navigability pain shows up as Slices 2–4 add reachable pages
- A new Slice 1.5 ("Dashboard filters — read-only") is inserted between Slice 1 and Slice 2.
- Slice 4 and Slice 6 entries in the plan now explicitly name the dashboard affordances they carry, so a reader looking for "where did the tag pills go" finds them by grep.
- Portability checklist gains one item: "No 0.4 feature dropped from the slice's scope without either user confirmation or a numbered slice entry."

**What did not change.**

- The Slice 1 code as shipped is unchanged. The correction is about traceability, not reverting the slice.
- The decision to hide per-card star buttons in Slice 1 (rather than render them with a broken POST click) stands; it simply now has a named home (Slice 1.5).

Reviewers scanning future slices should reject any commit whose message or reorg entry says "later slices", "future work", "will be addressed when needed", or equivalent phrasing without a grep-able numbered slice entry behind it.

---

## Slice 1 — Read-only dashboard (`?action=index`)

**Why.** The 0.4 dashboard (`controllers/dashboard.php::buildDashboardIndexData`) is a 500-line function that inlines five entry-family queries, Magnitu score merging, favourites lookup, filter-pill plumbing, tag derivation, scraper-config joins, and search all into one procedural block. Every time we added a new entry type (Lex, Leg, scraper), that function grew a new branch. Slice 1 extracts the core read path — "give me the newest N entries across every family, with scores and favourites attached" — into a bounded, satellite-safe, raw-data-returning repository, and proves the new plumbing end-to-end against the live database. Search, tag pills, favourites view, and the refresh button return in later slices.

**What moved.**

- `buildDashboardIndexData()` (0.4 `controllers/dashboard.php`) → `Seismo\Repository\EntryRepository::getLatestTimeline()` (0.5 `src/Repository/EntryRepository.php`). The new repo is ~400 lines (with comments) vs. 0.4's 500 procedural lines, and only covers the read path — fetchers moved out.
- `handleDashboard()` (0.4) → `Seismo\Controller\DashboardController::show()` (0.5 `src/Controller/DashboardController.php`). Orchestration only; no SQL, no data shaping.
- `seismo_magnitu_day_heading()` (0.4 `controllers/dashboard.php`), `seismo_feed_item_resolved_link()` (0.4 `controllers/rss.php`), `highlightSearchTerm()` (0.4 `controllers/rss.php`), `getCalendarEventTypeLabel()` + `getCouncilLabel()` (0.4 `controllers/calendar.php`) → **all collapsed into `views/helpers.php`** (0.5). Presentation-only, called by the partial; not allowed to touch the database.
- `views/index.php` (0.4, 298 lines of mixed layout + filter form + refresh button + nav + script) → `views/index.php` (0.5, ~110 lines: branding, count, partial include, and the two `<script>` blocks for per-card expand/collapse). The stripped features come back as their own slices.
- `views/partials/dashboard_entry_loop.php` — **copied verbatim** from 0.4 so the card layout (the feature we explicitly do not want to re-engineer) renders byte-identical to 0.4. Its contract with callers (`$allItems`, `$showDaySeparators`, `$showFavourites`, `$searchQuery`, `$returnQuery`) is now a fixed interface between controller and partial.
- `assets/css/style.css` — copied verbatim from 0.4.

**New wiring.** Request flow:

1. `index.php` registers `index` as the default action → `DashboardController::show`.
2. `Router::dispatch` releases the session write lock early (read-only action) and instantiates the controller.
3. `DashboardController::show` clamps `?limit` / `?offset` from the URL, calls `EntryRepository::getLatestTimeline($limit, $offset)`, requires `views/helpers.php` and `views/index.php`.
4. `EntryRepository` issues four bounded `SELECT`s (feed+feeds, email-table, lex_items, calendar_events), each capped at `$limit + $offset` and all wrapped in `entryTable()` so a satellite reads cross-DB from the mothership. Score and favourite attaches are two more queries against the **local** `entry_scores` / `entry_favourites` tables (never wrapped). Merge, date-sort, slice to `$offset`, `$limit`.
5. `views/index.php` includes `views/partials/dashboard_entry_loop.php`, which renders the cards.

**Explicit invariants this slice locks in (first real use, not speculative).**

- **Repositories return raw data.** Every row in `EntryRepository`'s output is exactly what MariaDB returned — unescaped, un-formatted. The partial applies `htmlspecialchars()` / `highlightSearchTerm` at render time.
- **Bounded queries.** `EntryRepository::MAX_LIMIT = 200`, `DashboardController::DEFAULT_LIMIT = 30`. `?limit=1000000` is silently capped.
- **Satellite safety.** Every entry-source SELECT goes through `entryTable()`. The email-table resolver uses `SHOW TABLES FROM \`mothership_db\`` in satellite mode.
- **Missing-table resilience.** `PDOException` with MySQL error 1146 on an entry-source table degrades to "no rows" for that family, so a fresh install where `calendar_events` is empty still shows feeds and emails. Non-1146 PDO errors re-raise — silent data loss is worse than a 500.
- **Time is UTC in the data layer.** `seismo_magnitu_day_heading()` is the only place the Slice-1 code converts timestamps to a calendar day, and it does so at view-render time (`strtotime('today')` with PHP already pinned to UTC). TODO for Slice 5: once there's a dedicated `SEISMO_VIEW_TIMEZONE`, wire it here so "Heute" matches Zurich rather than UTC midnight.

**Gotchas.**

- The 0.4 dashboard distinguishes scraper items from normal RSS via an `EXISTS (SELECT 1 FROM scraper_configs WHERE sc.url = f.url)` join. Slice 1 uses the simpler `feeds.source_type = 'scraper'` (or `feeds.category = 'scraper'`) classifier. For a fresh 0.5 database populated by 0.5's own fetchers this is exact; for 0.5 pointed at a 0.4 DB where source_type isn't always set on scraper feeds, some scraper rows may render with the generic "feed" wrapper (still correct, just the wrong pill colour). The `scraper_configs` join returns when the scraper fetcher ports in a later slice.
- The partial's favourite-toggle forms POST to `?action=toggle_favourite`, which doesn't exist in 0.5 yet. Slice 1 sets `$showFavourites = false` so the star buttons don't render at all — no broken POST surface.
- The Leg (calendar_events) fetch window (`>= CURDATE() - 14 days OR NULL`) is intentional: Leg items are forward-biased, so anchoring the sort to past+near-future mirrors how the 0.4 dashboard behaves, without pulling in 200 weeks of archived Bundesratsgeschäfte.
- `buildDashboardIndexData()` caps the merged timeline at 30 by default and 200 on search — same numbers as 0.5 (`DEFAULT_LIMIT = 30`, `MAX_LIMIT = 200`). No behaviour change for the primary view.
- `views/helpers.php` functions are declared at global scope (no namespace). This is deliberate: the partial is sacred and calls them as bare names. Each function is guarded with `function_exists()` so re-including the file is harmless.

**Test URL.** `https://www.hektopascal.org/seismo/?action=index` — expected to render a newest-first timeline of up to 30 entries drawn from the 0.4 MariaDB that 0.5 points at, with Magnitu score badges on scored entries and no filter UI yet.

---

## Decision 2026-04-19 (d) — Shared-host hardening (Slice 0 code + Slice 1/2 rules)

A final review raised four shared-hosting / stateless-LLM-integration concerns. All four accepted; three became rules effective from Slice 1, one required a code change in Slice 0 applied retroactively.

**1. Time is UTC everywhere.** Mixed time zones between PHP (often Europe/Zurich on Swiss shared hosts) and MariaDB (often UTC) silently break stateless `?since=<iso8601>` queries and retention cutoffs. Fix applied to `bootstrap.php` now:

- `date_default_timezone_set('UTC')` runs before any timestamp is created.
- `getDbConnection()` executes `SET time_zone = '+00:00'` immediately after connecting so NOW(), CURRENT_TIMESTAMP, and implicit conversions all speak UTC regardless of the server's configured TZ.

New rule in `core-plugin-architecture.mdc` ("Time is UTC everywhere"): repositories store/return UTC; formatters emit ISO-8601 with explicit `Z`; views are the only layer allowed to convert to local time.

**2. Bounded queries.** Shared-host `memory_limit` is typically 128 MB. A `SELECT *` across 180 days of `feed_items` with their `content` and `html_body` columns OOMs instantly. New rule: every list-returning repository method takes `int $limit` and `int $offset`, hardcoded max 200, no defaults returning everything. Single-row lookups don't need limits; pagination counts are the controller's concern via a dedicated `count()` method. Effective from Slice 1's `EntryRepository`.

**3. Composer boundary.** Slice 2 will pull in vendor libs (EasyRdf for SPARQL, possibly SimplePie for RSS) and would crash on startup if `vendor/autoload.php` isn't loaded before our custom autoloader tries to find third-party classes. Fix applied to `bootstrap.php` now — `file_exists()` + `require_once` for `vendor/autoload.php` before `spl_autoload_register()`. Safe even though `vendor/` doesn't exist yet.

**4. Transactional `upsertBatch`.** Plugin batches go in whole or not at all. New rule in `core-plugin-architecture.mdc` ("Transactional `upsertBatch`"): wrap `beginTransaction` / `commit`, rollback on any row failure, let the exception bubble to `RefreshAllService` which logs it to `plugin_run_log`. Partial state is worse than re-fetching.

If we ever observe "one bad row kills a 50-item fetch repeatedly" as a real operational pain, we graduate to per-row `try/catch` inside the transaction with a warnings array. Not speculative.

**What moved (code).**

- `bootstrap.php` section 0: `date_default_timezone_set('UTC')`.
- `bootstrap.php` section 3 renamed: Composer `vendor/autoload.php` loaded first (if present), then the `Seismo\*` PSR-4 autoloader.
- `getDbConnection()`: `SET time_zone = '+00:00'` after PDO connection.

**What moved (docs).**

- `core-plugin-architecture.mdc` gains three new sections: "Time is UTC everywhere", "Bounded queries", "Transactional `upsertBatch`". Mirrored into 0.4.
- `docs/consolidation-plan.md`: Slice 0 scope mentions UTC + vendor autoload; Slice 1 scope mentions bounded/raw/UTC; Slice 2 scope mentions transactional `upsertBatch` and `composer.json`; portability checklist gains three new items (no naive timestamps, no unbounded list methods, no `upsertBatch` without a transaction).

**Gotchas.**

- MariaDB `TIMESTAMP` columns store UTC internally regardless of session TZ; `DATETIME` columns store whatever's handed to them. With PHP + session both pinned to UTC, both behave identically. Choose `DATETIME` when writing new columns in migrations — `TIMESTAMP` has a 2038 limit.
- The hardcoded `$limit` cap (200) is intentionally global. A future "bulk export" endpoint that really needs more will stream via a generator method, not raise the cap.
- The 0.5 health page tested live yesterday was written **before** these changes. It still works — UTC pinning is additive. No re-deploy is mandatory for Slice 0 acceptance, but the next deploy will pick up the hardening automatically.

---

## Decision 2026-04-19 (c) — LLM-friendly export surface (pre-Slice-1)

A second review round asked how Seismo should look to an external LLM briefing workflow. Three commitments:

**1. Repositories stay raw — now a rule, effective from Slice 1.** The repository layer is the boundary between MariaDB and every consumer (HTML views, future Markdown/JSON formatters, CLI tools, tests). Putting `htmlspecialchars()` or `<br>` in a repository taxes every non-HTML consumer. The rule is added to `core-plugin-architecture.mdc` ("Repositories return raw data") and is load-bearing for the LLM story: a `MarkdownBriefingFormatter` in Slice 5 will consume the exact same raw arrays that the dashboard view consumes.

**2. Export is stateless — Option A.** The export endpoint filters by a client-supplied `?since=<iso8601>` or `?since_id=<id>`; Seismo does not remember what any consumer has already seen. The client tracks its own "last seen" marker (a local file, a cron env var, whatever). Zero schema changes, zero new write endpoints for this. Option B (Seismo remembers "already briefed") is explicitly **not** in v0.5 scope — it's real infrastructure (tags table, POST-back, multi-consumer coordination) that we only build when a second consumer actually needs to coordinate. We don't scaffold speculative coordination.

**3. Read-only API key, separate from Magnitu's.** A briefing script doesn't need write access. Slice 5 adds `export:api_key` alongside the existing Magnitu `api_key` in `system_config`. Two-key model — not a scopes table. If there's ever a third consumer class, we graduate. Validation is two lines: "is this the export key? then only export routes."

**What moves in Slice 5.**

- `Seismo\Formatter\MarkdownBriefingFormatter`, `Seismo\Formatter\JsonExportFormatter` — consume raw repository output; zero SQL, zero HTML.
- `?action=export_briefing&since=<iso8601>&format=markdown|json` and `?action=export_entries&since_id=<id>&format=json` — Bearer-token-authed with the read-only key.
- `export:api_key` row in `system_config` (added by migration in Slice 5a).

**Gotchas / deliberate non-goals.**

- No server-side scheduling of exports. The client drives. Seismo is a data provider, not a scheduler.
- No briefing generation inside Seismo. The LLM runs wherever the user's automation lives; Seismo ships raw Markdown for it to consume.
- No "undo briefing" / "re-mark" semantics. Because state is on the client, those are client-side concerns.

**What's locked in today.**

- Rule: `core-plugin-architecture.mdc` gains the "Repositories return raw data" section (mirrored into 0.4). This is the one decision that needs enforcement from Slice 1 onward — the rest is just written-down Slice 5 scope.
- Consolidation plan: "Repositories, views, formatters — the data/presentation split" section under Architectural Direction; Slice 5 expanded with the export surface; new "Machine-readable export" section with the three decisions.

---

## Decision 2026-04-19 (b) — External review settled (pre-Slice-1)

A software-architect review surfaced five blind spots common to prototypes-becoming-production. Outcomes, in the order raised:

**1. Unified logging + circuit breaker.** Accepted logging, rejected breaker. A single `plugin_run_log` table (`plugin_id`, `run_at`, `status`, `item_count`, `error_message`, `duration_ms`) is populated by `RefreshAllService` in Slice 3 and read by the diagnostics surface. No parallel `system_logs` — PHP's `error_log()` is the floor for non-plugin errors. **No circuit breaker in v0.5** — auto-pausing plugins hides signal the user needs; `try/catch` + structured log is enough until production shows we need more.

**2. Data retention / GC.** Accepted and **promoted to a repository contract**. Every family repository (`LexItemRepository`, `CalendarEventRepository`, `FeedItemRepository`, `EmailRepository`) ships with `prune(\DateTimeImmutable $olderThan, array $keepPredicates): int` from day one — not bolted on later. A `Seismo\Service\RetentionService` composes keep-predicates from settings and calls each repo at the end of `refresh_cron.php`. Policy for v0.5: `feed_items` 180d, `emails` 180d, `lex_items` unlimited, `calendar_events` unlimited. Favourites, `investigation_lead`/`important` scored rows, and manually labelled rows are always kept. Dry-run preview is mandatory.

**3. Config standardization (JSON vs DB).** Accepted with a scheduled refactor, not a rush job. Slice 5a renames `magnitu_config` → `system_config` (the table has been misnamed since 0.3; it's always been a generic k/v store) and folds `lex_config.json` + `calendar_config.json` into `system_config` rows keyed `plugin:<identifier>`. Retires the JSON files. Breaking but one-shot — cleaner backups, cleaner satellite story, cleaner setup wizard.

**4. Plugin dry-run / test mode.** Accepted, **no interface change needed**. The plugin contract already forbids touching the DB, so `fetch()` is a pure function from config + network → items. The diagnostics "Test" button in Slice 3 just calls `fetch()` and renders the first N items without invoking a repository. Free feature given the contract we already chose.

**5. Native session auth.** Accepted as a **dormant backbone**. Slice 3 ships:
- `Seismo\Http\AuthGate` + `AuthController` + login view
- `SEISMO_ADMIN_PASSWORD_HASH` constant in `config.local.php.example`
- When the constant is unset → `AuthGate::check()` is a no-op, no behaviour change
- When the constant is set → protected routes redirect to `?action=login`; session flag grants access
- `health`, `login`, and `magnitu_*` are whitelisted; `health` strips version strings when auth is enforced
- Magnitu API keeps its own Bearer-token auth, independent of this switch

Rationale: the moment refresh endpoints land, an open URL is a DoS + third-party-rate-limit-cost vector. Backbone is cheap to build clean now and impossible to retrofit cleanly later; keeping it dormant means zero friction for the single-user workflow until the user chooses to flip it on.

**What moved.** Nothing yet — pre-slice decisions captured for Slice 2 onward.

**New rules.**

- `core-plugin-architecture.mdc` extended with "Data retention" and "Plugin run log" sections (mirrored into 0.4).
- `auth-dormant-by-default.mdc` — new, small, load-bearing. Mirrored into 0.4.
- `docs/consolidation-plan.md` updated: Slice 3 widened (auth + run log + diagnostics), new Slice 5a (config unification + retention service), Slice 6 gains retention UI polish. A "Decisions settled after external review" section closes out the open questions the review raised.

---

## Decision 2026-04-19 — Core / Plugin split (pre-Slice-1)

Not a code slice; an architectural decision that shapes every slice from 2 onward. Recorded here because it changes where future ports land and what the runner looks like.

**Why.** Seismo's fetchers mix two very different risk profiles. RSS, IMAP, scraping are ours end-to-end and stable. Fedlex, RechtBund, EU Lex (SPARQL), Légifrance, Parlament.ch (OData) are brittle: upstream schemas and auth flows change without notice. In 0.4 a single plugin blowing up could cascade through `refreshAllSources()`. 0.5 must isolate the brittle edge.

**What this means architecturally.**

- `src/Core/` — things we control; crashes are bugs.
- `src/Plugin/<Name>/` — one folder per third-party adapter; crashes are expected and contained.
- A single interface, `Seismo\Service\SourceFetcherInterface`, with five small methods (`getIdentifier`, `getLabel`, `getEntryType`, `getConfigKey`, `fetch`). Plugins **MUST NOT** touch the DB.
- `Seismo\Service\PluginRegistry` — hardcoded array, no filesystem scanning.
- `Seismo\Service\RefreshAllService` — iterates Core fetchers and plugins, wraps every plugin call in `try/catch (\Throwable)`, records per-run status.

**Persistence (Option B — shared family tables).** Plugins do not own tables. They return DTOs; the runner writes them via the family repository:

| Family | Table | Writers |
|---|---|---|
| Legal text | `lex_items` | LexFedlex, LexEu, LexLegifrance, RechtBund (all plugins) |
| Parliamentary business (Leg) | `calendar_events` | ParlCh (plugin) |
| RSS / Substack | `feed_items` | Core |
| Email | `emails` (unified in Slice 4) | Core |

Plugin-specific fields live in `metadata JSON`. Rationale: preserves the polymorphic dashboard timeline (the consistent-card-layout achievement stays intact), keeps Magnitu's `entry_type` enum and API contract stable, keeps `entryTable()` satellite wrapping in one place (the repository).

**Config.** Plugins share the existing family JSONs (`lex_config.json`, `calendar_config.json`). Each plugin points at its block via `getConfigKey()`. No new per-plugin config files for v0.5.

**Failure surface.** Plugin errors show up in Settings / diagnostics (extending today's "feed diagnostics" area and the 0.5 `?action=health` surface). Per-plugin last-run status with `ok` / `skipped` / `error(message)` + timestamp. No dashboard banner, no email alerts. `error_log` remains the server-side source of truth.

**What moved.** Nothing yet — this is a pre-slice decision. Adding here because it's the load-bearing shape for Slice 2 onward.

**New wiring (to be implemented).**

```
Web "Refresh all" / refresh_cron.php
  → Seismo\Service\RefreshAllService::run()
    → Core fetchers (RSS, Mail, Scraper) — called directly, crashes are bugs
    → PluginRegistry::all()
      → for each SourceFetcherInterface:
          try {
            $items = $plugin->fetch($configBlock);
            $familyRepository->upsertBatch($plugin->getEntryType(), $items);
            $status[$plugin->getIdentifier()] = 'ok';
          } catch (\Throwable $e) {
            error_log(...); $status[...] = 'error: '.$e->getMessage();
          }
    → Scoring pass (Core)
    → Persist run summary for diagnostics surface
```

**Gotchas / what we deliberately did not decide.**

- No filesystem-scan plugin discovery — explicitly hardcoded in `PluginRegistry`. Easy to swap later.
- No per-plugin sidecar tables yet. Granted case by case if a plugin truly needs state (OAuth tokens, cursors). Not speculative.
- RSS is **Core**, not a plugin, even though upstream feeds occasionally break — because we own the parser. "Plugin" = we call someone else's API, not "upstream is unreliable."
- No change to the schema for this decision. All four family tables already exist and already have `metadata JSON`.

**Rule:** `.cursor/rules/core-plugin-architecture.mdc` (mirrored into 0.4 for workspace consistency).

---

## Slice 0 — Skeleton: bootstrap, router, health, migrate CLI

**Why.** 0.4 wired almost everything through `index.php` directly: a giant switch, `require`s of `config.php` (which itself ran DDL on every HTTP request), and a mix of procedural helpers scattered across `controllers/*.php`. Before porting features, the new codebase needs:

- a clean boot path with no DDL side-effects on request;
- a router that separates "what to do" from the list of actions;
- an early observable endpoint to prove PHP + DB + config reach the host correctly;
- a CLI entry point for future schema migrations.

**What moved.**

| 0.4 | 0.5 |
|---|---|
| `config.php` (mixed: constants, helpers, DDL, scoring) | `bootstrap.php` (constants, autoloader, DB + basePath + satellite helpers, `e()` for views). DDL and scoring explicitly **not** here — deferred to Slice 2+. |
| `initDatabase()` run on every request via `config.php` | `migrate.php` + `Seismo\Migration\MigrationRunner` + `Migration001BaseSchema` loads `docs/db-schema.sql` (schema version **17**, idempotent `CREATE IF NOT EXISTS`) |
| Giant `switch ($_GET['action'])` in `index.php` | `Seismo\Http\Router` with action → `Class::method` registration, preserves 0.4's early session-lock release for read-only routes |
| `getBasePath()` / `isSatellite()` / `entryTable()` / `entryDbSchemaExpr()` in `config.php` | Same functions in `bootstrap.php`, identical signatures — kept global on purpose (lightweight OOP, not full DI). |
| `htmlspecialchars(...)` repeated in views | `e()` helper in `bootstrap.php` |
| (no equivalent) | `Seismo\Repository\SystemRepository` (MySQL version) and `Seismo\Repository\MagnituConfigRepository` (key/value reads) — repositories are now the **only** place SQL lives |
| (no equivalent) | `Seismo\Controller\HealthController` + `views/health.php` serving `?action=health` |

**New wiring.**

```
HTTP request
  → index.php (session_start, require bootstrap.php, instantiate Router)
  → Router::dispatch($action)
    → maybeReleaseSession() for read-only routes
    → [Class, method] lookup + class_exists/method_exists guard
    → Controller::method()
      → Repository calls (SQL isolated here)
      → require view template (native PHP, uses e() for escaping)
```

PSR-4 autoloader in `bootstrap.php` maps `Seismo\` → `src/`. `require_once` (not `require`) to be safe against double-includes.

**Gotchas.**

- `MagnituConfigRepository::get()` catches `PDOException` and returns `null` when the table doesn't exist. This is deliberate: a brand-new database should render "schema version: not initialised — run `php migrate.php`" rather than 500. After `php migrate.php` on an empty DB, `magnitu_config` exists and `schema_version` is **17**.
- **Live / shared DB:** Running `php migrate.php` against the same database 0.4 already migrated is safe — every statement is `CREATE TABLE IF NOT EXISTS`; the runner skips work when `schema_version >= 17`. Expect: `Nothing to do — schema is already at version 17.`
- **No CLI on host:** `?action=migrate&key=…` runs the same `MigrationRunner` when `SEISMO_MIGRATE_KEY` is set in `config.local.php`. Plain-text response; `hash_equals` on the key. Documented in `docs/setup-wizard-notes.md` as the primary path for URL-only shared hosting.
- The autoloader does **not** throw when a class file is missing; the Router does, at dispatch time, with a clear 500 + `error_log`. This is the right place to fail loudly.
- `config.local.php.example` ships in the repo, `config.local.php` is gitignored. Plesk's Git deploy will **not** create it on the server — it has to be created by hand (or eventually by the setup wizard, see `docs/setup-wizard-notes.md`).
- Webspace layout verified: 0.5 lives at `/seismo/`, 0.4 reference at `/seismo-staging/`. Base path auto-derived via `getBasePath()`. Live health check passed against MariaDB 10.6.20, schema 17, PHP 8.2.30.

**Status.** Shipped and verified live. Health URL: `https://www.hektopascal.org/seismo/?action=health`.
