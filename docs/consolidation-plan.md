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

### Core vs Plugin split

Code is split into **Core** (things we control; stable by nature — RSS parsing, IMAP, scraping, scoring, Magnitu API, dashboard) and **Plugin** (third-party adapters: Fedlex, RechtBund, EU Lex SPARQL, Légifrance, Parlament.ch, …). Plugins implement `Seismo\Service\SourceFetcherInterface` and live in `src/Plugin/<Name>/`. `RefreshAllService` wraps every plugin in `try/catch` so one broken upstream never takes down the app.

**Persistence decision: Option B — plugins share existing family tables.** Plugins return DTOs; the runner writes them via the family repository (`LexItemRepository`, `CalendarEventRepository`, …). Plugin-specific fields live in the existing `metadata JSON` column. This preserves the polymorphic dashboard timeline (card layout), the stable Magnitu `entry_type` enum, and single-place `entryTable()` satellite wrapping.

**Configuration decision:** plugins share the existing family JSONs (`lex_config.json`, `calendar_config.json`); each plugin exposes a `getConfigKey()` pointing at its block.

**Failure surface decision:** plugin errors surface in Settings / diagnostics (extending the existing feed-diagnostics area and the 0.5 `?action=health` surface). No banner on the dashboard, no email alerts. `error_log` stays the ground truth for server logs.

Full rule: `.cursor/rules/core-plugin-architecture.mdc`.

### Guardrails to keep in Cursor rules

1. **Move off global procedural handlers.** `handleLexPage()` → `LexController::show()`; DB reads → `LexRepository`; HTTP → `LexFetcherService`.
2. **`entryTable()` is sacred.** Any query touching `feed_items`, `feeds`, `emails` (unified), `sender_tags`, `email_subscriptions`, `lex_items`, `calendar_events` (Leg) **must** go through it. `entry_scores`, `magnitu_config`, `magnitu_labels` are always local.
3. **Decouple fetch from persist.** Fetchers return data; Repositories do `INSERT ... ON DUPLICATE KEY UPDATE`.
4. **Slim `config.php`.** Scoring belongs in a `ScoringService`. DDL belongs in a CLI migration, not on every request.
5. **Unify email schema.** Retire the older `emails` shape in favour of the `fetched_emails` structure (proper IMAP UID handling). Ship a one-time migration with a dry-run and clear backup guidance.
6. **One refresh pipeline.** Web `Refresh all` and `refresh_cron.php` call the **same** function (today they drift — cron misses the Leg fetch).
7. **Native PHP views stay.** No Twig/Blade/Plates in 0.5. Use partials + small view helpers. Escaping discipline via `htmlspecialchars()` everywhere.

## Execution strategy — vertical slices, not waterfall

A strict five-phase waterfall risks **nothing runnable** until late. Instead: stay within the target architecture while delivering **one thin end-to-end slice** per step.

### Slice 0 — Skeleton & health check

- `bootstrap.php` (autoload, config, PDO, `isSatellite()`, `entryTable()`).
- Minimal class-based router in `index.php` (preserve read-only session-lock release).
- `migrate.php` CLI that runs the DDL (port today’s `initDatabase()` contents).
- One trivial route (e.g. `?action=health`) returning DB + version status.
- **Definition of done:** fresh checkout + `config.local.php` + `php migrate.php` + `php -S` loads a health page.

### Slice 1 — Read-only dashboard

- `EntryRepository` (polymorphic reads, satellite-aware).
- `DashboardController` that lists entries using the existing `dashboard_entry_loop.php` partial unchanged (cards are protected).
- No writes yet. Satellite mode should work against a mothership DB without scraping.
- **Definition of done:** feed renders on both mothership and satellite; all card types look identical to 0.4.

### Slice 2 — Lex as the reference plugin port

- Introduce `Seismo\Service\SourceFetcherInterface` and a shared HTTP base client.
- Implement `LexFedlexPlugin` (first plugin) + `LexItemRepository` (first family repo) + `LexController` + Lex page + settings tab for Lex only.
- Plugin writes nothing directly; controller/refresh handler calls plugin → repository.
- **Definition of done:** Lex list view and “Refresh Lex” work end-to-end through the new plugin layering — template for every subsequent plugin and family repo.

### Slice 3 — Unified refresh pipeline + plugin runtime boundary

- `Seismo\Service\PluginRegistry` (hardcoded array of instantiated plugins).
- `Seismo\Service\RefreshAllService` shared by web + CLI: iterates Core fetchers first, then plugins with `try/catch` per plugin. Records per-run status.
- Rebuild `refresh_cron.php` as a thin shell that calls `RefreshAllService`.
- Add **Leg** (ParlCh plugin) to the shared pipeline so cron and web match.
- Plugin status rendered in the diagnostics / health surface.
- **Definition of done:** cron and “Refresh all” execute the same steps; Leg is included in both; a failing plugin logs + shows red in diagnostics and the rest of the pipeline keeps running.

### Slice 4 — Remaining entry sources

- **Core fetchers:** RSS, Substack, Mail (with email schema unification migration), Scraper — each ported as a Core service (not a plugin).
- **Plugins:** LexEu, LexLegifrance, RechtBund (as Jus variant), Parl MM, any other third-party adapter — each follows the Slice 2 template.
- Unify email schema under `emails` built from today’s `fetched_emails` structure; provide migration script.

### Slice 5 — Magnitu boundary

- `MagnituConfigRepository`, `ScoringService` (out of `config.php`).
- API controllers (`magnitu_entries`, `magnitu_scores`, `magnitu_recipe`, `magnitu_labels`, `magnitu_status`).
- Satellite keys, Leg API exclusion stays intentional until we decide otherwise.

### Slice 6 — Admin / settings polish

- Settings tabs split into clean partials (one concern each).
- Retire `ai_view` experiments or fold into one admin path.
- Styleguide kept as design source of truth.

## Portability checklist (applies to every slice)

- [ ] No hardcoded domains or absolute URL prefixes except intentional (`SEISMO_MOTHERSHIP_URL`, OAuth, external APIs).
- [ ] All internal links and redirects use `getBasePath()`.
- [ ] Filesystem paths via `__DIR__` only; downloaded configs flag that they embed the current server’s paths.
- [ ] New tables and ENUM widenings live in `migrate.php`, not request-time.
- [ ] Cron changes mirrored in `RefreshAllService` (or equivalent).
- [ ] Setup-wizard notes updated with any new requirement (extensions, writable paths, env vars).

## Views

Stay with **native PHP templates** in 0.5. Keep:

- `views/partials/dashboard_entry_loop.php` — unchanged behaviourally; any change reviewed per entry type.
- Small helper functions (e.g. `e()` for `htmlspecialchars()`) rather than an engine.
- Per-source pages remain plain PHP — same philosophy, same deployability.

Revisit a templating engine only if real pain emerges (duplicated markup, escaping bugs). Not preemptively.

## Open decisions

- **Email schema unification** — exact column mapping from `emails` → unified structure. Needs a migration draft before Slice 4.
- **`ai_view`** — keep as admin-only, retire, or fold into Magnitu UI? Decide before Slice 6.
- **Magnitu Leg API** — when (or whether) to lift the `calendar_event` exclusion. Product decision, not technical.
