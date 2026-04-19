# Seismo 0.5 — reorganisation log

Technical companion to `README.md`, written **live** during the 0.4 → 0.5 consolidation. Explains, slice by slice, what moved, why, and how the new wiring works. The audience is anyone (including future-me) who knows 0.4 and needs to find where something went in 0.5.

- Entries are **newest on top**.
- Every entry follows the same four-part shape: **Why**, **What moved**, **New wiring**, **Gotchas**.
- References use **file paths**, not line numbers (they drift).
- Companion doc for users (in-app about page, `views/about.php`) is left alone until the consolidation is done — see `.cursor/rules/documentation-strategy.mdc`.

---

## Slice 4 — Unified emails, Core RSS/scraper/mail hook, tag-filter pills

**Why.** 0.4 split mail between `emails` and `fetched_emails`, kept RSS/scraper/mail outside the plugin runner, and built dashboard tag filters against the full entry surface. Slice 4 merges the email schema, runs Core fetchers through the same `RefreshAllService::runAll()` entry point as plugins (with `core:*` ids in `plugin_run_log`), adds SimplePie-based RSS + a minimal HTML scraper path, and restores dashboard filter pills (0.4-shaped GET params).

**What moved.**

| Area | 0.5 |
|---|---|
| Dual email tables | `Migration003EmailsUnified` (schema **v19**) merges `fetched_emails` into `emails` (BIGINT ids, IMAP/body columns). `DROP` `fetched_emails` after merge. `getEmailTableName()` in `bootstrap.php` returns `emails`. |
| Core refresh | `Seismo\Service\CoreRunner` runs `core:rss`, `core:scraper`, `core:mail` before plugins. Throttle matches plugin semantics (stdout-only when throttled; `plugin_run_log` stores outcomes except noise). **`core:mail` is a stub** (no in-process IMAP yet); mail ingestion is the **CLI** under `fetcher/mail/` writing to unified `emails`. Diagnostics “Refresh” records a skipped row only when `$force` (web), not on cron — avoids log spam. |
| RSS | `Seismo\Core\Fetcher\RssFetchService` (SimplePie 1.9). Normalises title/link/body per consolidation-plan fetcher contract. |
| Scraper | `Seismo\Core\Fetcher\ScraperFetchService` + `FeedItemRepository::listFeedsForScraperRefresh()` (feeds with `source_type=scraper` or URL in `scraper_configs`). |
| Feeds persistence | `Seismo\Repository\FeedItemRepository` — transactional `upsertFeedItems`, `prune()` stub (180d policy lands with RetentionService). |
| `RefreshAllService::boot()` | Injects `CoreRunner` + `FeedItemRepository` + shared `PluginRunLogRepository`. |
| Diagnostics | `views/diagnostics.php` — “Core fetchers” block above plugins; same `plugin_run_log` read model. |
| Dashboard filters | `Seismo\Repository\TimelineFilter` + GET params `fc`, `fk`, `lx`, `etag`, `nocal`. `EntryRepository` applies SQL filters per family; favourites view filters hydrated rows in PHP. `FavouriteController` whitelist extended for filter params. |
| Composer | `simplepie/simplepie` (^1.9). |

**Gotchas.**

- **Plugin deferrals (user decision D4):** LexEu / LexLegifrance / Jus / Parl MM plugins were **not** added in this slice — registry unchanged beyond Slice 3.
- **`core:mail` rows:** Always **skipped** with a message pointing at the CLI mail fetcher — until in-process IMAP is implemented, do not expect `mail_imap_*` in `magnitu_config` to change behaviour. **`plugin_run_log`:** skipped row is written on **forced** runs (Refresh all / per-core Refresh on diagnostics), not on CLI cron (`$force = false`), so cron does not append a useless row every tick.
- **Retention:** `FeedItemRepository::prune()` is callable; `RetentionService` (Slice 5a) will invoke family prunes — no automatic feed/email prune in this slice’s cron beyond plugin runs.

**Test URLs.**

- `?action=migrate&key=…` — expect schema **19** after deploy.
- `?action=diagnostics` — Core fetchers block + plugins; Refresh all runs core + plugins.
- `?action=index&fk=substack&nocal=1` — filter pills + Leg hidden from merged timeline.
- CLI: `php refresh_cron.php` — stdout includes `core:rss` / `core:scraper` / `core:mail` lines.

---

## Correction 2026-04-19 — Slice 3 logout CSRF + top-bar logout button

**Why this entry exists.** A post-Slice-3 review found two gaps between the Slice 3 reorg entry below and the shipped code. Both were minor, neither had made it to the webspace as of this correction — filing here so the failure mode is visible rather than quietly patched.

**What was wrong.**

1. **`?action=logout` accepted GET and bypassed CSRF.** `AuthController::logout()` only enforced the CSRF check on `POST` — any `GET` (including a cross-origin `<img src=".../index.php?action=logout">`) would silently drop the session. Logout is a state-changing operation and belongs under the same "all mutating POSTs require CSRF" contract the slice committed to.
2. **Logout button was only on the dashboard top-bar.** The Slice 3 entry below says "Top-bar action buttons added on Dashboard / Lex / Leg pages: Lex, Leg, Diag, plus Logout (POST + CSRF) when auth is enabled." In reality only `views/index.php` rendered the conditional logout form. Lex / Leg / Diagnostics showed no way to log out without retyping a URL.

**What moved.**

- `Seismo\Controller\AuthController::logout()` now rejects any non-`POST` method and redirects home, *then* requires `CsrfToken::verifyRequest()`. Short comment in the controller explains why (third-party image/link GETs never carry the token).
- `views/lex.php`, `views/leg.php`, `views/diagnostics.php` gain the same conditional logout form as `views/index.php`: shown only when `AuthGate::isEnabled() && AuthGate::isLoggedIn()`, POSTs to `?action=logout` with `$csrfField` (Lex/Leg — already passed by their controllers) or `CsrfToken::field()` direct (Diagnostics — already imports `CsrfToken`).
- `views/diagnostics.php` also gains the missing **Lex** and **Leg** top-bar links so its nav shape matches the other pages.

**Gotchas.**

- **CSRF-token-after-logout quirk.** `AuthGate::logout()` calls `session_regenerate_id(true)` (it rotates the session) and then `redirectToLogin()`. The next request starts a fresh session; the old token is gone. This is intended — once you're logged out, there's nothing to protect.
- **Dormant auth still hides the button.** With `SEISMO_ADMIN_PASSWORD_HASH` unset, `AuthGate::isEnabled()` is `false` and the logout form renders nowhere. No behaviour change for the default single-user workflow.
- **No DB / schema change.** This is a controller + views fix only; `plugin_run_log`, migrations, CSRF token plumbing, and the rest of Slice 3's surface are untouched.

**Test URLs.**

- When auth is **dormant** (`SEISMO_ADMIN_PASSWORD_HASH` unset): `?action=index`, `?action=lex`, `?action=leg`, `?action=diagnostics` all render without a Logout button. `GET ?action=logout` redirects home without side effects.
- When auth is **enforced**: log in, then confirm each of those four pages shows the Logout button in its top-bar, and that clicking it ends the session and lands on the login form. Manually visiting `GET ?action=logout` (URL bar) should *not* log you out — it should bounce you back to the dashboard.

---

## Slice 3 — Unified refresh pipeline, master cron, auth backbone, diagnostics (`?action=diagnostics`, `?action=refresh_all`, `?action=refresh_plugin`, `?action=plugin_test`, `?action=leg`, `?action=login`, `?action=logout`, `refresh_cron.php`)

**Why.** Slice 2 shipped a Fedlex-only `PluginRunner`. Slice 3 generalises it into the runner the rest of the consolidation is built on, ports the remaining "scrapes a 3rd-party API" surface (Parlament.ch — Leg) onto the same plugin contract, and lands the operational scaffolding the cron + UI need: a structured run log, a single Master Cron entry, web refresh routes, a diagnostics page, the dormant auth backbone, and CSRF on every mutating POST. After Slice 3, *new plugins are one folder + one PluginRegistry line + one row in the diagnostics table* — no controller / no cron / no view changes per plugin.

**What moved.**

| 0.4 | 0.5 |
|---|---|
| `controllers/calendar.php` `refreshParliamentChEvents()` + `refreshParliamentChSessions()` | `Seismo\Plugin\ParlCh\ParlChPlugin::fetch()` (`src/Plugin/ParlCh/ParlChPlugin.php`). No SQL. Uses `BaseClient` for HTTP; whitelists `language` against `LANGUAGE_CODES`; drops empty/invalid rows per the data normalization contract. `getMinIntervalSeconds(): 4 * 60 * 60`. |
| Ad-hoc INSERTs into `calendar_events` from `controllers/calendar.php` | `Seismo\Repository\CalendarEventRepository` (`src/Repository/CalendarEventRepository.php`). `entryTable('calendar_events')` everywhere. Transactional `upsertBatch`. `prune()` is a no-op (Leg unlimited per default policy). `upsertBatch()` / `prune()` throw if `isSatellite()`. |
| `getCalendarConfig()` / `saveCalendarConfig()` (0.4 `config.php`) | `Seismo\Config\CalendarConfigStore` (`src/Config/CalendarConfigStore.php`) reading/writing `calendar_config.json` next to `bootstrap.php`. **Gitignored**; `calendar_config.example.json` is the committed shape. |
| `controllers/calendar.php::handleCalendarPage()` | `Seismo\Controller\LegController::show()` + `views/leg.php`. Date-grouped, future-first, status / council / event-type pills. Uses `seismo_format_utc()` for the "Refreshed" line (UTC → Europe/Zurich at the view layer). |
| `?action=refresh_calendar` POST handler | Two routes: `?action=refresh_parl_ch` (per-plugin force=true) → `LegController::refreshParlCh` → `RefreshAllService::runPlugin('parl_ch', true)`; and `?action=save_leg_parl_ch` → `LegController::saveLegParlCh` (CSRF-checked, validates ranges, normalises language). Legacy `?action=calendar` URL still resolves (alias to `LegController::show`). |
| `Seismo\Service\PluginRunner` (Slice 2 scaffolding) | **Renamed and generalised** to `Seismo\Service\RefreshAllService` (`src/Service/RefreshAllService.php`). One service for `runAll(force)`, `runPlugin($id, force)`, and `testPlugin($id, peek)`. Dispatches to the right family repo via `match($plugin->getEntryType())`. `boot(\PDO $pdo)` static factory shared by web + CLI. Slice 2's `PluginRunner.php` is deleted. |
| `controllers/dashboard.php::refreshAllSources()` (mostly) | `RefreshAllService::runAll()`. Slice 3 covers plugin refreshes only — Core RSS / mail / scoring graduate in later slices, then the web "Refresh all" button can replace 0.4's button entirely. |
| `cron/*.php` per-source CLI scripts (0.4) | **One** `refresh_cron.php` at the project root. CLI-only (refuses non-CLI), satellite no-op, calls `RefreshAllService::boot($pdo)->runAll(false)`. Throttled-skipped plugins go to stdout (cron mail) but *not* to `plugin_run_log`. See "Master Cron" below. |
| (no equivalent) | `Seismo\Service\Http\BaseClient` + `Response` + `HttpClientException` (`src/Service/Http/`). Shared HTTP wrapper: 30s timeout, custom UA, single retry on 429/503, both cURL and stream backends. ParlChPlugin uses it; future plugins (LexEu, RechtBund, Légifrance) will inherit. |
| (no equivalent) | `Seismo\Repository\PluginRunLogRepository` + `Migration002PluginRunLog` (schema **v18**). DDL appended to `docs/db-schema.sql`. `recentForPlugin()`, `latestPerPlugin()`, `lastSuccessfulRunAt()`. Missing-table tolerance via `PdoMysqlDiagnostics::isMissingTable()`. |
| (no equivalent) | `Seismo\Service\PluginRunResult` DTO with `ok` / `skipped` / `error` factories. Persisted to `plugin_run_log` *except* throttle skips. |
| (no equivalent) | `Seismo\Http\AuthGate` + `Seismo\Controller\AuthController` + `views/login.php`. Dormant unless `SEISMO_ADMIN_PASSWORD_HASH` is defined. `AuthGate::check($action)` runs before dispatch; whitelists `health`, `login`, `logout`, `migrate`, `magnitu_*`. |
| (no equivalent) | `Seismo\Http\CsrfToken`. Wired into every mutating POST: `toggle_favourite`, `refresh_fedlex`, `save_lex_ch`, `refresh_parl_ch`, `save_leg_parl_ch`, `refresh_all`, `refresh_plugin`, `plugin_test`, `login`, `logout`. Single rotating session-bound token, single-use rotation on success. |
| `?action=health` (full info, anyone) | `HealthController` degrades when auth is enabled and the visitor is not logged in: `dbStatus: ok|not ok` only. Full diagnostics require login. |
| (no equivalent) | `Seismo\Controller\DiagnosticsController` + `views/diagnostics.php` at `?action=diagnostics`. One status row per registered plugin (latest run from `plugin_run_log`, throttle window, "next allowed run"), "Refresh all", "Refresh now (this plugin)", "Test fetch (no save)" buttons. Test result peek (first 5 rows) returned as a one-shot session flash. |
| Nav was non-existent in 0.5 | Top-bar action buttons added on Dashboard / Lex / Leg pages: Lex, Leg, Diag, plus Logout (POST + CSRF) when auth is enabled. |

Plugins registered for Slice 3: `fedlex` (LexFedlex, Slice 2) and `parl_ch` (ParlCh, this slice). Both use a 4-hour throttle.

**New wiring.**

```
HTTP "Refresh all"          ──┐
HTTP "Refresh now" / plugin ──┤
HTTP "Test fetch" / plugin  ──┤
                              │ all share
   refresh_cron.php (CLI) ──┤  RefreshAllService::boot($pdo)
                              │
                              ▼
              ┌── runAll(force=false) ── PluginRegistry::all()
              ├── runPlugin($id, force=true)
              └── testPlugin($id, peek=5)   ─── plugin->fetch() only

Inside runOne():
  isSatellite()         → skipped(satellite)              [logged]
  throttle && !force    → skipped(throttled)              [stdout only]
  empty($block.enabled) → skipped(disabled)               [logged]
  fetch() throws        → error(message)                  [logged]
  success               → ok(count)                       [logged]
  on success: family repo upsertBatch() (transactional)
```

Throttle source of truth: `SourceFetcherInterface::getMinIntervalSeconds()` + `PluginRunLogRepository::lastSuccessfulRunAt($id)`. `error` and `skipped` rows are *not* counted as "last run" — a broken upstream gets retried on every cron tick instead of being silenced for the throttle window.

Single-cron migration note: on the host, the existing 0.4 per-source crontab lines should be replaced with one entry, e.g. `*/5 * * * * /usr/bin/php /var/www/seismo/refresh_cron.php`. The mail and scraper crons in 0.4 stay in place until Core RSS / Mail port (later slice).

**Gotchas.**

- **Throttled skips are not in `plugin_run_log`.** A 5-minute master cron with two plugins on a 4h throttle would otherwise write ~576 rows/day of pure noise. The "is throttled?" indicator in diagnostics is computed from `last_ok + min_interval` against `now()`, not by reading skipped rows.
- **`PluginRunLogRepository::lastSuccessfulRunAt` only counts `status = 'ok'`.** Don't bump the throttle window via fake "ok" writes; the diagnostics page also reads from this column.
- **`diagnostics` is registered as NOT read-only** so the controller's `unset($_SESSION['plugin_test_result'])` after rendering actually persists. Lex/Leg/Dashboard stay read-only.
- **Login form's POST handler is overlay-registered** (`index.php` swaps the `login` action to `AuthController::handleLogin` only when `REQUEST_METHOD === POST`). Slightly unusual but keeps the route table flat.
- **`AuthGate::check` runs before dispatch and after `Router::register` calls.** Whitelisted public actions: `health`, `login`, `logout`, `migrate`, `magnitu_*`. Anything else redirects to `?action=login` when auth is on.
- **CSRF token rotates on accepted POST.** A user with two forms loaded simultaneously will see "session expired" on the second submit; this is acceptable for a single-user admin app and is the documented behaviour. If we ever want concurrent forms (Slice 6 polish), graduate to per-form tokens.
- **`refresh_cron.php` refuses non-CLI requests** with HTTP 403. Anyone discovering the file in the URL bar can't trigger an upstream hit.
- **Satellite mode is a no-op for everything plugin-related.** `refresh_cron.php` exits 0 immediately on satellites; `RefreshAllService::runOne()` records a `skipped` row (one per plugin) so diagnostics doesn't look broken; `LegController::refreshParlCh` and `LexController::refreshFedlex` still POST cleanly but the runner short-circuits.
- **`PluginRunResult::message` is null on `ok`.** When you want the count, read `$result->count`. The `record()` writer also stores `null` in `error_message` for `ok` rows so the column is meaningful.
- **`RefreshAllService::resolveConfigBlock()` only knows two `entry_type`s** (`lex_item`, `calendar_event`). Adding a Core family (e.g. `feed_item` from RSS) requires teaching this method *and* `persist()` about the new repo; the contract is local to this one file by design.

**Test URLs.**

- `?action=diagnostics` — lists Fedlex + ParlCh; "never run" until the first cron tick or a manual refresh.
- `?action=refresh_all` (POST from diagnostics) — populates `plugin_run_log`; flash summary visible.
- `?action=leg` — date-grouped Parlament CH list; refresh + settings forms work; legacy `?action=calendar` resolves to the same controller.
- `?action=login` — only useful when `SEISMO_ADMIN_PASSWORD_HASH` is set in `config.local.php`. To generate a hash: `php -r "echo password_hash('yourpass', PASSWORD_DEFAULT) . PHP_EOL;"`.
- `php refresh_cron.php` (manual SSH or Plesk "run task now") — produces stdout per plugin; rerunning within 4h shows throttle skip lines but no new DB rows for the skipped plugins.

---

## Slice 2 — Lex / Fedlex reference plugin (`?action=lex`, `?action=refresh_fedlex`, `?action=save_lex_ch`)

**Why.** Establish the Core vs Plugin boundary with a real third-party adapter (Fedlex SPARQL) before Slice 3’s unified refresh service. The Lex page must stay useful on a shared DB populated by 0.4: list all enabled `lex_items` sources with pills, while 0.5 only *writes* Swiss Fedlex rows.

**Pull-forward (plan traceability).** `PluginRegistry` + `PluginRunner` were originally sketched for Slice 3 in an early plan outline; they ship here as **Fedlex-only** scaffolding so the refresh path is not a one-off. Slice 3 generalises into `RefreshAllService` + full plugin list + `plugin_run_log`. Documented in `docs/consolidation-plan.md` Slice 2 / Slice 3 and `.cursor/rules/slice-scope-fidelity.mdc` (pull-forwards).

**What moved.**

- `refreshFedlexItems()` + `parseFedlexType()` (0.4 `controllers/lex_jus.php`) → `Seismo\Plugin\LexFedlex\LexFedlexPlugin::fetch()` (0.5 `src/Plugin/LexFedlex/LexFedlexPlugin.php`). No SQL in the plugin; returns normalised rows for `lex_items`; empty titles / bad act URIs dropped before persist.
- Ad-hoc Lex INSERT (0.4) → `Seismo\Repository\LexItemRepository::upsertBatch()` with `entryTable('lex_items')`, transaction-wrapped all-or-nothing upsert. `listBySources()` + `getLastFetchedBySources()` for the page; `prune()` returns 0 (Lex unlimited per default policy). `upsertBatch()` / `prune()` throw if `isSatellite()` (defence in depth — runner already skips fetch on satellites).
- `getLexConfig()` file I/O (0.4 `config.php`) → `Seismo\Config\LexConfigStore` reading/writing `lex_config.json` beside `bootstrap.php`, with `defaultConfig()` merged when keys are missing. **`lex_config.json` is gitignored** (mutable per deploy); **`lex_config.example.json`** is the committed shape reference (like `config.local.php.example`).
- Missing-table tolerance for `lex_items` queries shares `Seismo\Repository\PdoMysqlDiagnostics::isMissingTable()` with `EntryRepository` (same 1146 + message fallback as the latter used privately before).
- `handleLexPage()` (0.4) → `Seismo\Controller\LexController::show()` + `views/lex.php`. POST refresh → `LexController::refreshFedlex` → `Seismo\Service\PluginRunner::runFedlex()` only (not multi-source Lex refresh).
- `SourceFetcherInterface`, `PluginRegistry` (Fedlex only), `PluginRunResult`, `PluginRunner` under `src/Service/`.
- **Deferred to Slice 3 (named):** shared HTTP/SPARQL wrapper with timeouts / UA / retry — Fedlex uses `EasyRdf\Sparql\Client` directly in Slice 2 only; see plan Slice 3.

**New wiring.**

1. `index.php` registers `lex` (read-only), `refresh_fedlex` (POST), `save_lex_ch` (POST, CH block only).
2. `PluginRunner` loads Lex config → `ch` block → `LexFedlexPlugin::fetch()` → `LexItemRepository::upsertBatch()`. Failures `error_log` with plugin id; no `plugin_run_log` until Slice 3.
3. `isSatellite()` short-circuits fetch in the runner (same spirit as 0.4); the Lex page hides refresh/save and explains read-only satellite behaviour.
4. Composer: `easyrdf/easyrdf` in `composer.json` / `composer.lock`. Bootstrap loads `vendor/autoload.php` first. `vendor/` is gitignored; run `composer install` after deploy.
5. Lex “Refreshed:” timestamps: repository returns UTC `DateTimeImmutable`; `views/helpers.php::seismo_format_lex_refresh_utc()` converts to **Europe/Zurich** for display (view layer only).

**Gotchas.**

- Only `source = ch` is refreshed in 0.5; EU/DE/FR/Parl MM rows are read-only until their plugins ship (Slice 4+). The Lex view states this explicitly.
- Full multi-source “Refresh Lex” and cron parity remain Slice 3 (`RefreshAllService`).
- CH Fedlex `ch_language` is whitelisted (`DEU`,`FRA`,`ITA`,`ENG`,`ROH`) in `LexFedlexPlugin::normalizeFedlexLanguage()` (used from save + fetch) to block SPARQL injection via the language URI segment.
- CSRF tokens for `refresh_fedlex` / `save_lex_ch` / `toggle_favourite` are **Slice 3** together with `AuthGate` — see amended `docs/consolidation-plan.md` Slice 3 DoD.

**Transactional DoD (manual verification).** There is no automated test harness yet. To confirm all-or-nothing behaviour: temporarily inject a second row into the batch inside `LexFedlexPlugin::fetch()` with an intentionally invalid `celex` (e.g. 300+ chars if your schema limits `celex`), run `POST ?action=refresh_fedlex`, then confirm `SELECT COUNT(*)`, `MAX(fetched_at)`, or row checksum for `source='ch'` is unchanged vs. before — the transaction should roll back entirely. Remove the inject before shipping.

**Test URL.** `?action=lex` — multi-source list when the DB has rows; `POST ?action=refresh_fedlex` updates Fedlex only when not satellite and `ch.enabled` is true.

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

## Slice 1.5 — Search, favourites view, star toggle (`?q=`, `?view=favourites`, `?action=toggle_favourite`)

**Why.** Slice 1 deliberately shipped without search, favourites mode, or working stars so the read path could be reviewed in isolation. This slice restores those dashboard affordances without tag pills (Slice 4) or refresh/nav (Slices 3 / 6). Variant **1.5b**: the POST route ships alongside star buttons so nothing 404s.

**What moved.**

- `EntryRepository::searchTimeline($q, $limit, $offset)` — `LIKE` across feed_items (joined to feeds), resolved email table (column list from `INFORMATION_SCHEMA` per family), lex_items (`title`/`description`), calendar_events (`title`/`description`/`content`). Prepared statements only; same merge/sort/score attach as `getLatestTimeline`.
- `EntryRepository::getFavouritesTimeline($limit, $offset)` — reads local `entry_favourites` (never `entryTable()`), hydrates rows per family with chunked `IN` queries, caps pair-list at 5000 favourites (newest-starred first via `created_at`), merge by date, slice.
- `Seismo\Repository\EntryFavouriteRepository` — `toggle()` mirrors 0.4 `toggleEntryFavourite()` (`INSERT IGNORE` / `DELETE`).
- `Seismo\Controller\FavouriteController::toggle` — POST-only, validates `entry_type` / `entry_id`, redirects with relative `?` + preserved `return_query` (from `DashboardController::buildReturnQuery()`).
- `DashboardController::show` — branches newest vs favourites vs search; `$showFavourites = true`; builds `return_query` from current `$_GET`.
- `views/index.php` — GET search form, Newest/Favourites links, session flash for errors, three empty-state messages (default / favourites / search).

**New wiring.**

1. `index.php` registers `toggle_favourite` → `FavouriteController::toggle` (not a read-only route — session stays writable for flash).
2. `DashboardController` passes `$currentView`, `$emptyTimelineHint`, and keeps `MAX_OFFSET = 0`.

**Gotchas.**

- **Favourites + search:** In this slice, `?view=favourites` ignores `?q=` for the repository call (favourites list is not filtered by search). The search box still appears; clearing search or switching to Newest restores search behaviour. A tighter merge can wait until tag filters (Slice 4) or explicit product spec.
- **Per-table email search:** Searchable columns are chosen from a fixed allowlist intersected with `INFORMATION_SCHEMA` so `emails` vs `fetched_emails` both work.
- **Email date-column cache** is now keyed by table name so `resolveEmailDateColumns()` cannot return the wrong column set if multiple shapes were ever probed in one request.

**Test URL.** `https://www.hektopascal.org/seismo/?action=index` — search, toggle favourites view, star a card, confirm redirect and persistence. `?action=toggle_favourite` POST only.

**Amendment — Slice 1.5 review fixes (fetch + favourite + plan).** `fetchFeedItems()` gains `fi.hidden = 0` to match search and 0.4. `searchTimeline()` escapes SQL LIKE wildcards in `?q`. `EntryFavouriteRepository::toggle()` is DELETE-then-INSERT (atomic vs SELECT/DELETE race); `ALLOWED_ENTRY_TYPES` is a single public const; duplicate key 1062 treated as starred. `FavouriteController` whitelists `return_query` to `q`, `view`, `limit`, `offset`; POST→GET redirect uses **303**; success flash on star/unstar. `docs/consolidation-plan.md` Slice 3 gains CSRF in DoD; Slice 6 notes optional FULLTEXT for heavy `feed_items` search.

**Amendment — defensive cards (same slice, follow-up).** Thin RSS rows (title-only, or teaser-only) and blank/`#` links are common in real feeds. `views/partials/dashboard_entry_loop.php` now: promotes stripped `description` when stripped `content` is empty, then falls back to title for the preview body; uses `seismo_is_navigable_url()` so titles are not wrapped in `href=""` or `href="#"` (feed/substack already guarded; scraper, Lex, Leg fixed). Lex falls back from `eurlex_url` to `work_uri`. Email shows `(No body text)` when both text and HTML bodies are empty. `docs/consolidation-plan.md` records a future **fetcher output contract** (minimum viable entry at ingest) plus optional per-feed full-text extraction later — not implemented until the Core RSS / `RefreshAllService` port.

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
