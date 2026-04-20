# Seismo

**Seismo** is a self-hosted monitoring dashboard that pulls **RSS feeds**, **Substack-style feeds**, **IMAP mail**, **scraped web pages**, **legal gazette updates (Lex)**, and **Swiss parliamentary business (Leg)** into one **unified timeline** with full-text search, favourites, and filter pills.

Behind the scenes it runs a **deterministic recipe scorer** (keywords, source weights, class rules) so the timeline stays sortable before any ML touches it. Optionally, you connect **Magnitu v3** — a **Python companion app** checked out beside Seismo on disk (not vendored into this repo) — which syncs **relevance scores** and training labels over a small **HTTP API** documented in **`.cursor/rules/magnitu-integration.mdc`** and kept in lockstep with the Magnitu client. A separate **read-only export API** (`export_briefing`, `export_entries`) feeds Markdown or JSON to LLM scripts, cron, or automation without granting write access to scores.

The codebase targets **PHP 8.2**, **MariaDB/MySQL**, and **vanilla PHP** (no Redis, no background worker daemons): one web app plus a **CLI cron** entry is enough for typical shared hosting.

---

## Features

### Unified timeline

- One reverse-chronological stream of every active **entry family** (feeds, mail, Lex, Leg, scraper).
- **Search** across titles and bodies; **favourites** with per-card stars; **filter pills** for feed type, categories, Lex sources, email tags, and similar axes.

### Core sources

| Area | What it does |
|------|----------------|
| **Feeds** | RSS and Substack-style sources; per-feed URLs and optional categories. |
| **Mail** | IMAP ingest into a unified `emails` table; **Subscriptions** UI with domain-first matching (e.g. `@example.com`), tags, and unsubscribe where supported. |
| **Scraper** | Scheduled fetches for configured URLs (with link-following where configured). |
| **Leg** | Swiss Federal Assembly business (motions, sessions, publications, hearings) via the Parliament OData API — *not* a personal calendar. |

### Lex — legislative plugins

Third-party adapters live under `src/Plugin/` and share the **`lex_items`** table:

| Source | Mechanism |
|--------|-----------|
| **EUR-Lex** | EU Publications Office **SPARQL** (CELEX / CDM–oriented queries). |
| **Fedlex** | Swiss federal law **SPARQL** (Fedlex endpoint). |
| **Germany (recht.bund)** | **RSS** from `recht.bund.de`. |
| **France (Légifrance)** | **PISTE OAuth2** + search API (JORF-oriented filters); requires **ext-curl**. |
| **Parliament press (“Parl MM” / SDA)** | **SharePoint list** integration as **`feed_items`** via `feeds.source_type = parl_press` (core fetcher, not Lex). |

### Scoring & Magnitu

- **Recipe engine** — PHP-side scoring from stored recipe JSON (`keywords`, `source_weights`, `classes`, …). Good enough to badge and sort until Magnitu overwrites with `score_source = magnitu`.
- **Magnitu v3** — Bearer `magnitu_*` actions: entry export, score ingest, labels, recipe round-trip, status. Contract is shared between repos; do not change JSON shapes without updating the Python client.
- **Satellite mode** — optional second instance reads **entry** tables from a **mothership** database on the same MySQL server while keeping **scores, labels, and config** local (multi-topic Magnitu profiles).

### Export API (machine-readable)

- **`?action=export_briefing`** — Markdown digest for a time window (Bearer **`export:api_key`**, distinct from the Magnitu write key).
- **`?action=export_entries`** — JSON entries with score metadata.

Use these for **LLM briefings**, **n8n**, **Raycast**, or **cron + curl** — the same surface that replaces the old 0.4 **“AI view”** HTML page. See in-app **About** (`?action=about`) for a short user-facing summary.

### Operations & safety

- **Master refresh** — Web **Refresh** (Timeline or Diagnostics) and **`refresh_cron.php`** share **`RefreshAllService::runAll()`** so cron and the UI stay aligned (plugins + core fetchers + retention hooks as implemented).
- **Retention** — Per-family policies (defaults e.g. 180 days for feeds/mail; Lex/Leg often unlimited); dry-run before destructive prune.
- **Session auth** — **Off by default** (`SEISMO_ADMIN_PASSWORD_HASH` unset). Turn it on in `config.local.php` when the instance is exposed; Magnitu and export keys stay **Bearer**-based and independent.
- **Migrations** — Versioned PHP classes under `src/Migration/`; run via CLI `php migrate.php` or **`?action=migrate`** with `SEISMO_MIGRATE_KEY` when SSH is unavailable.

---

## Requirements

| Requirement | Notes |
|-------------|--------|
| **PHP** | ≥ **8.2** |
| **Extensions** | **`pdo_mysql`** (required). **`curl`** recommended for some Lex paths. **`imap`** only if you use the **core IMAP** mail fetcher. |
| **Database** | **MariaDB** or **MySQL** with **utf8mb4**. Application and DB timestamps are treated as **UTC** end-to-end. |
| **Composer** | **On the server:** optional — this repo ships a production **`vendor/`** tree so deploy can be `git pull` only. **On your laptop:** use Composer for dev tools (e.g. PHPUnit) and to regenerate `vendor/` after lockfile changes. |

---

## Quick start

### 1. Get the code

```bash
git clone <your-fork-or-upstream-url> seismo
cd seismo
```

If you develop locally and need PHPUnit or other dev packages:

```bash
composer install
```

For a **production-style** tree (what we commit for shared hosts):

```bash
composer install --no-dev --optimize-autoloader
```

### 2. Configure the database

- Copy **`config.local.php.example`** to **`config.local.php`** and set **`DB_HOST`**, **`DB_NAME`**, **`DB_USER`**, **`DB_PASS`** (and optional **`DB_PORT`**).
- **First install without a file yet:** open **`?action=setup`** in the browser — it tests credentials, then writes **`config.local.php`** or shows a **copy-paste** block if the directory is not writable (never loosen permissions to `0777`).

### 3. Run migrations

Set **`SEISMO_MIGRATE_KEY`** in `config.local.php` to a long random secret, then either:

```bash
php migrate.php
```

or call **`?action=migrate`** over HTTPS with the key (Bearer, POST body, or query — see **`docs/setup-wizard-notes.md`**). Remove or empty the key after you are satisfied.

### 4. Verify

Open **`?action=health`** — expect database **ok** and a **schema version** line. Then open **`?action=index`** for the timeline.

### 5. Cron (production)

Register **one** CLI job (example cadence):

```cron
*/5 * * * * /usr/bin/php /absolute/path/to/seismo/refresh_cron.php
```

The script refuses HTTP — it is meant for the host crontab only. It respects per-plugin **throttles**; the web **Refresh** buttons pass **`force=true`** and bypass throttles for interactive use.

### 6. Magnitu & export keys

After the app runs, seed keys in **`system_config`** (Settings UI **Magnitu** tab, or SQL):

- **`api_key`** — Magnitu v3 write client.
- **`export:api_key`** — read-only export client (**must not** reuse the Magnitu write key).

---

## Useful entry points

| URL | Purpose |
|-----|---------|
| `?action=index` | Dashboard / timeline (**Refresh** runs full pipeline). |
| `?action=health` | DB + schema check (degraded when session auth is on and you are logged out). |
| `?action=about` | Short product / export overview in the browser. |
| `?action=diagnostics` | Plugin + core fetcher status, throttles, manual refresh & dry-run test fetch. |
| `?action=settings` | Global settings (Magnitu, retention, UI defaults). |
| `?action=feeds` / `scraper` / `mail` | Module-owned **Items \| Sources** admin pattern. |
| `?action=lex` / `leg` | Lex and Leg pages with per-source refresh & config. |

Full **Magnitu / export** request shapes live in **`.cursor/rules/magnitu-integration.mdc`** (authoritative contract for both repos).

---

## Repository layout

```
index.php              # Front controller: session, router, AuthGate
bootstrap.php          # config.local.php, UTC, Composer autoload, Seismo\ autoload, helpers
refresh_cron.php       # CLI-only master refresh (+ retention tail)
src/Controller/        # HTTP orchestration — no SQL
src/Repository/        # All application SQL + entryTable() for satellite reads
src/Service/           # RefreshAllService, plugin registry, HTTP client, retention
src/Plugin/            # Lex, Leg, … — SourceFetcherInterface, no SQL
src/Core/              # Core fetchers (RSS, mail, scraper, parl_press, …)
src/Migration/         # Numbered schema migrations
views/                 # Native PHP templates (escape on output)
docs/db-schema.sql     # Reference schema; migrator consumes where applicable
```

**Extend Seismo:** add a plugin = new directory under **`src/Plugin/<Name>/`** plus one line in **`PluginRegistry`**. Add a route = **`index.php`** + controller method + (for mutating POSTs) **CSRF** and **`AuthGate`** rules as documented in **`.cursor/rules/`**.

---

## Configuration reference

| File / constant | Role |
|-----------------|------|
| **`config.local.php`** | Database credentials, optional `SEISMO_MIGRATE_KEY`, `SEISMO_ADMIN_PASSWORD_HASH`, satellite keys (`SEISMO_MOTHERSHIP_DB`, …), branding. **Never commit.** |
| **`system_config` table** | Magnitu keys, recipe JSON, retention policies, `plugin:*` JSON blobs (post–5a migration). |
| **`.htaccess`** | Typical shared-host routing (if Apache); adjust for nginx on your stack. |

Portability rules (subfolder installs, no hardcoded hosts) are summarised in **`.cursor/rules/deployment-portability.mdc`**.

---

## Testing

```bash
composer install          # includes PHPUnit
./vendor/bin/phpunit
```

Tests live under **`tests/`**; they assume a configured dev database only when explicitly required by the test case.

---

## Consolidation & 0.4 mapping

This tree is **Seismo 0.5** — a structured port of the earlier **0.4** codebase (same product goals, cleaner boundaries: repositories own SQL, plugins wrap third-party APIs, one refresh pipeline for web + cron).

If you are **diffing behaviour against 0.4** or tracing **where a feature moved**, use **[`README-REORG.md`](README-REORG.md)** — a chronological **migration log** (newest entries first), not the primary product readme. High-level goals and open product follow-ups remain in **[`docs/consolidation-plan.md`](docs/consolidation-plan.md)**.

---

## Further reading

- **[`docs/setup-wizard-notes.md`](docs/setup-wizard-notes.md)** — shared-host pitfalls, migrate URL patterns, wizard notes.
- **[`.cursor/rules/feature-development-architecture.mdc`](.cursor/rules/feature-development-architecture.mdc)** — where new code belongs (controllers vs repositories vs plugins).
