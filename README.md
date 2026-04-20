# Seismo 0.5

Consolidated PHP app for monitoring legislation, parliamentary business (Leg), feeds, and mail — with optional **Magnitu v3** scoring and a read-only **export API** for briefings and automation.

Built from [seismo_0.4](../seismo_0.4). Numbered consolidation slices (**0 → 9**) are shipped; **Slice 10** is documentation hygiene only. Deep per-slice notes and 0.4 → 0.5 mappings live in **[`README-REORG.md`](README-REORG.md)** — read that file when you need line-level history.

## Requirements

- **PHP** 8.2+ with **PDO MySQL** (`pdo_mysql`). Optional: **ext-imap** (core mail fetch), **ext-curl** (some Lex plugins).
- **MariaDB / MySQL** 10.x+ (UTC session is pinned in code).
- **Composer** is optional on the **server**: production **`vendor/`** is committed so a plain `git pull` deploy works on hosts without CLI Composer.

## Quick start (developer or shared host)

1. **Upload** the tree (preserve `vendor/` if you deploy from Git).
2. **Configuration**
   - Copy [`config.local.php.example`](config.local.php.example) to `config.local.php` and edit `DB_*`, **or**
   - With no `config.local.php` yet, open **`?action=setup`** in the browser (first-run stub: tests DB, writes or shows a copy-paste block, then use **`?action=health`** to verify).
3. **Migrations** — set `SEISMO_MIGRATE_KEY` in `config.local.php`, then run once: `?action=migrate` with your key (see [`docs/setup-wizard-notes.md`](docs/setup-wizard-notes.md)). Or CLI: `php migrate.php`.
4. **Smoke test** — `?action=health` (DB + schema version). Open **`?action=index`** for the Timeline.

**Cron** — one entry for the unified refresh pipeline: `php /path/to/refresh_cron.php` (CLI only; not exposed over HTTP). See [`refresh_cron.php`](refresh_cron.php) and `README-REORG.md`.

## Useful URLs (relative to your install, e.g. `/seismo/index.php`)

| URL | Purpose |
|-----|--------|
| `?action=health` | DB connectivity + schema version (uptime-friendly when auth is on). |
| `?action=index` | Dashboard / Timeline; top bar **Refresh** runs the same pipeline as Diagnostics. |
| `?action=about` | In-app product overview + export API / 0.4 “AI view” replacement. |
| `?action=setup` | First-run config helper when `config.local.php` is missing (see Slice 9). |
| `?action=diagnostics` | Plugin/core fetcher status, throttles, manual refresh. |
| `?action=migrate` | Web migrations when `SEISMO_MIGRATE_KEY` is set. |

**Magnitu v3** and **export** clients use Bearer actions documented in [`.cursor/rules/magnitu-integration.mdc`](.cursor/rules/magnitu-integration.mdc) (`magnitu_*`, `export_*`).

## Repository layout (sketch)

| Path | Role |
|------|------|
| [`index.php`](index.php) | Front controller: session, bootstrap, router, `AuthGate`. |
| [`bootstrap.php`](bootstrap.php) | Config load, UTC, Composer + `Seismo\*` autoload, `getDbConnection()`, `getBasePath()`, satellite helpers. |
| [`src/Controller/`](src/Controller/) | Thin HTTP handlers (no SQL). |
| [`src/Repository/`](src/Repository/) | **Only** layer with raw SQL for DB access (plus numbered migrations under `src/Migration/`). |
| [`src/Service/`](src/Service/) | `RefreshAllService`, plugin registry, HTTP client, retention. |
| [`src/Plugin/`](src/Plugin/) | Third-party source adapters (`SourceFetcherInterface` — no SQL). |
| [`views/`](views/) | Native PHP templates; escape with `e()` / `htmlspecialchars`. |
| [`docs/db-schema.sql`](docs/db-schema.sql) | Reference schema; migrator reads it where applicable. |

Extension points: new plugins = one folder under `src/Plugin/<Name>/` + one line in `PluginRegistry`; new routes = `index.php` + controller.

## Status vs 0.4

- **Reference (running):** `seismo_0.4` — e.g. `https://www.hektopascal.org/seismo-staging/`
- **0.5 target:** e.g. `https://www.hektopascal.org/seismo/` — same architectural rules as documented in [`.cursor/rules/`](.cursor/rules/).

## Where to look next

- **[`docs/consolidation-plan.md`](docs/consolidation-plan.md)** — goals, slice order, open product follow-ups.
- **[`docs/setup-wizard-notes.md`](docs/setup-wizard-notes.md)** — shared-host pitfalls and wizard notes.
- **[`.cursor/rules/feature-development-architecture.mdc`](.cursor/rules/feature-development-architecture.mdc)** — routing table for where new code belongs.

## Composer / `vendor/`

Production dependencies (**EasyRdf**, **SimplePie**) live in **committed** `vendor/` (built with `composer install --no-dev --optimize-autoloader`). After changing `composer.json` / `composer.lock`, regenerate `vendor/` the same way and commit. Local PHPUnit: `composer install` (with dev) on your machine — see `README-REORG.md` (*committed `vendor/`* section).
