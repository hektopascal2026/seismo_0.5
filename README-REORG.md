# Seismo 0.5 — reorganisation log

Technical companion to `README.md`, written **live** during the 0.4 → 0.5 consolidation. Explains, slice by slice, what moved, why, and how the new wiring works. The audience is anyone (including future-me) who knows 0.4 and needs to find where something went in 0.5.

- Entries are **newest on top**.
- Every entry follows the same four-part shape: **Why**, **What moved**, **New wiring**, **Gotchas**.
- References use **file paths**, not line numbers (they drift).
- Companion doc for users (in-app about page, `views/about.php`) is left alone until the consolidation is done — see `.cursor/rules/documentation-strategy.mdc`.

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
| `initDatabase()` run on every request via `config.php` | `migrate.php` (CLI-only, skeleton for now — no migrations defined yet) |
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

- `MagnituConfigRepository::get()` catches `PDOException` and returns `null` when the table doesn't exist. This is deliberate: a brand-new database should render "schema version: not initialised — run `php migrate.php`" rather than 500. The migrator itself must still be strict.
- The autoloader does **not** throw when a class file is missing; the Router does, at dispatch time, with a clear 500 + `error_log`. This is the right place to fail loudly.
- `config.local.php.example` ships in the repo, `config.local.php` is gitignored. Plesk's Git deploy will **not** create it on the server — it has to be created by hand (or eventually by the setup wizard, see `docs/setup-wizard-notes.md`).
- Webspace layout verified: 0.5 lives at `/seismo/`, 0.4 reference at `/seismo-staging/`. Base path auto-derived via `getBasePath()`. Live health check passed against MariaDB 10.6.20, schema 17, PHP 8.2.30.

**Status.** Shipped and verified live. Health URL: `https://www.hektopascal.org/seismo/?action=health`.
