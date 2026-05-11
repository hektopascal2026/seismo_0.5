# Seismo

**Seismo** is a self-hosted monitoring dashboard that pulls **RSS feeds**, **Substack-style feeds**, **IMAP mail**, **scraped web pages**, **legal gazette updates (Lex)**, and **Swiss parliamentary business (Leg)** into one **unified timeline** with full-text search, favourites, and filter pills.

Behind the scenes it runs a **deterministic recipe scorer** (keywords, source weights, class rules) so the timeline stays sortable before any ML touches it. Optionally, you connect **Magnitu v3** — a **Python companion app** checked out beside Seismo on disk (not vendored into this repo) — which syncs **relevance scores** and training labels over a small **HTTP API** documented in **`.cursor/rules/magnitu-integration.mdc`** and kept in lockstep with the Magnitu client. A separate **read-only export API** (`export_briefing`, `export_entries`) feeds Markdown or JSON to LLM scripts, cron, or automation without granting write access to scores.

The codebase targets **PHP 8.2**, **MariaDB/MySQL**, and **vanilla PHP** (no Redis, no background worker daemons): one web app plus a **CLI cron** entry is enough for typical shared hosting.

**0.5.3** makes **RSS and scraper** refresh **chunked by default** (bounded batches per cron tick or per manual-refresh time budget, with cursor state in `system_config`) so shared hosts stay within PHP time limits with hundreds of sources. **`refresh_cron.php`** acquires a **MySQL advisory lock** so overlapping cron invocations (e.g. Plesk every minute while a tick is still running) exit immediately instead of duplicating fetches. **Settings → General** offers an opt-in **legacy** mode that restores the old single-pass sweep for RSS + scraper. **0.5.2** added **per-module refresh** on **Feeds**, **Scraper**, **Mail**, and **Lex** (one click runs only that area’s ingest), and keeps the **timeline** toolbar refresh light by skipping **Lex** legislation pulls (use **Settings → Diagnostics** or **cron** for a full run). **0.5.1** added **previews** on those module pages to validate sources before saving.

**May 2026 tuning** — recipe scoring was clustering visibly around 48–52 (the formula’s no-signal attractor) after the n-gram expansion shipped on Apr 21. Three Seismo-side adjustments plus a Magnitu-side floor-weight fix restore signal-to-noise — documented under **[Scoring tuning (May 2026)](#scoring-tuning-may-2026)** below: recipe n-gram window rolled back **5 → 3**, default alert threshold lowered **0.75 → 0.60**, a one-time operational nudge to cycle Magnitu sync, and curated anchor concepts now ship at their seeded weights instead of being squashed by the export cap. None of these change schema or the API contract.

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
| **Scraper** | Scheduled fetches for configured URLs (with link-following where configured). See **Scraper (web sources)** below. |
| **Leg** | Swiss Federal Assembly business (motions, sessions, publications, hearings) via the Parliament OData API — *not* a personal calendar. |

### Scraper (web sources)

Scraper feeds use **`src/Core/Fetcher/ScraperFetchService.php`** for both **preview** and **production** so behaviour matches.

| Aspect | Behaviour |
|--------|------------|
| **Preview (Sources UI)** | POST `scraper_preview` from **`?action=scraper`**. Stateless dry-run: up to **5** successful articles, **no** random delay between page fetches. |
| **Production** | Core fetcher **`core:scraper`** (Settings → Diagnostics refresh, full **Refresh**, **`refresh_cron.php`** via the same refresh pipeline as other core fetchers). Up to **20** articles per scraper **feed** per run. |
| **Politeness** | In **link-following** mode (non-empty link pattern), a **random 1–3 second** `sleep` runs **before each article fetch after the first**, to reduce the risk of IP blocks. |
| **HTTP** | Fetches go through **`BaseClient::getWebPage()`** with a **desktop Chrome–style User-Agent** and **browser-like `Accept` / `Accept-Language` headers** (and cURL content encoding where available). |
| **Row shape** | Each item is normalised for **`FeedItemRepository`**: **`guid`** = article URL (truncated to 500 chars), **`content_hash`** = **`md5()`** of the extracted plain-text **`content`**, so upserts stay idempotent and duplicates are not multiplied by re-fetch. |
| **Extraction** | Readability-style main text + optional **CSS `date_selector`** for publish time (see `ScraperContentExtractor`). Link mode uses a **substring** match on absolute URLs, **same host** as the listing page, **fragment** stripped for dedupe. |

Config rows live in **`scraper_configs`**; feeds are tied by URL, with `scraper_link_pattern` / `scraper_date_selector` taken from the first matching enabled config (**`FeedItemRepository::listFeedsForScraperRefresh`**). There is **no** admin UI for preview/production caps yet — they are the constants above.

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

#### Magnitu highlights, Label training, and satellites

The **Magnitu highlights** view (`?action=magnitu`) shows highly scored entries using the same entry cards as the timeline, including an optional **star** (favourite). **Stars** are stored in **`entry_favourites`** and are **always local** to the Seismo instance you are using (mothership or satellite). They are **not** exported or imported by Magnitu; there is no `magnitu_*` endpoint for favourites.

**Training labels** used by Magnitu (`investigation_lead`, `important`, `background`, `noise`) are edited on the in-app **Label** tab (`?action=label`). Those rows live in **`magnitu_labels`** on the same instance. Magnitu v3 pulls and pushes them with **`magnitu_labels`** GET/POST, authenticated with that instance’s Magnitu API key.

For a **satellite** tied to a Magnitu profile:

1. Use **seismo-generator** (sibling repo) to build the deployable tree; it prints the satellite **base URL** and **dedicated API key** to paste into **that** profile in Magnitu.
2. In Magnitu, **pull entries** always uses the **global** mothership connection (shared article pool). **Pull labels** and **push** (scores, recipe, labels) use the profile’s **satellite URL + API key** when both are set, so label work and model output round-trip to **that** satellite only — not mixed with another host. Magnitu rejects an incomplete pair (URL without key or the reverse).

Authoritative HTTP details: **`.cursor/rules/magnitu-integration.mdc`**.

### Scoring tuning (May 2026)

`RecipeScorer` is a deterministic fallback for entries that **Magnitu v3** has not (yet) scored. Its math is a softmax over per-class keyword sums weighted by `class_weights = [1.0, 0.66, 0.33, 0.0]` for `[investigation_lead, important, background, noise]`. For an entry with **zero matches** the formula resolves to:

```
relevance = 0.25 × (1.0 + 0.66 + 0.33 + 0.0) = 0.4975  → badge shown as 50
```

That **0.4975 "no-signal" attractor** is where every recipe-scored row gravitates unless several anchor concepts fire in the same direction. With current recipe magnitudes (typical unigram weight 0.12, multi-word 0.24), a single match shifts the badge by ~2 points — so it is structurally easy for the timeline to look "all 48–52".

In April 2026 two changes amplified that:

- **N-gram window expanded from 2 → 5** (`RecipeScorer::MAX_NGRAM`). Per article, this roughly **doubled** the matched-token count, and many recipe entries have *conflicting* class-weights across `background`/`important`/`investigation_lead` ("iran", "trump", "ukraine", "in der schweiz", …). More matches in conflicting directions softens the softmax distribution toward uniform — i.e. toward 50.
- **Swiss dictionary expansion** added French / Italian / English aliases for recipe German keywords, which is *good* for surfacing foreign-language coverage but compounds the same dilution.

This section documents the **May 2026 mitigations** applied to `seismo_0.5`. Each is small and reversible. None changes schema or the **Magnitu v3** HTTP contract.

#### 1. Recipe n-gram window: 5 → 3

`src/Core/Scoring/RecipeScorer.php` — `MAX_NGRAM` constant.

```php
private const MAX_NGRAM = 3;   // was 5
```

Trigrams cover every signal-bearing concept in the current recipe (e.g. `member states only`, `third country`, `eu eea`, `equivalence decision`). The 4- and 5-grams the distiller emits tend to be format boilerplate (`category bekanntmachung bekanntmachung`, `english tight query`, …) that fire indiscriminately across an entire source — they fragment the softmax denominator without adding usable signal.

This intentionally **diverges** from Magnitu's distiller token window (which still emits up to 5-grams). The deterministic Seismo fallback is allowed to be a more conservative subset of the same feature space — Magnitu's full ML output overlays it via the precedence rule in `EntryScoreRepository::upsertRecipeScore()` and is the source of truth for any entry it has scored.

When **Magnitu v3** trims its distillation to anchor-concept-floor weights (open product follow-up), Seismo's `MAX_NGRAM` may revisit either direction; the rollback to 3 is the right default until then.

#### 2. Alert threshold default: 0.75 → 0.60

`system_config.alert_threshold` is what the timeline ("alert" badge), `MagnituHighlightsController`, and each module page's "score≥threshold" pill compare against. Existing installs keep whatever the admin had saved — only **new installs** and **fallback paths** (config row missing) see the new default.

Existing files updated (form default + the parallel `0.75` fallbacks across controllers):

| Where | What |
|---|---|
| `views/partials/settings_magnitu.php` | Form default in the **Settings → Magnitu** number input. |
| `src/Controller/DashboardController.php` (`resolveAlertThreshold`) | Fallback when `system_config.alert_threshold` is null/empty. |
| `src/Controller/MagnituAdminController.php` (`saveConfig`) | POST-body default if the form submits an empty `alert_threshold`. |
| `src/Controller/MagnituHighlightsController.php` (`show`) | Local initialisation before reading `system_config`. |
| `src/Controller/FeedController.php`, `MailController.php`, `ScraperController.php` | Local initialisation (badge rendering) before reading `system_config`. |

**To apply the new default on an existing instance:** open **Settings → Magnitu**, change the **Alert threshold** field from `0.75` to `0.60`, and **Save preferences**. There is no migration — your stored value is what wins.

Rationale: with current recipe weights, even a strong document hitting three anchor concepts at full weight (e.g. `member states only` 0.235 + `third country` 0.193 + `eu eea` 0.193 in `investigation_lead`) lands at relevance ≈ 0.58. Reaching 0.75 requires logits that the current distiller does not produce in practice. Lowering to 0.60 makes the threshold operationally meaningful — it now corresponds to "two or three anchor-concept matches in the same direction" rather than "essentially never". Raise it again once Magnitu's distiller emits **floor-weighted anchor concepts** for `third_country_discrimination`, `equivalence_loss`, `single_market_carveout`, etc.

#### 3. Cycle Magnitu sync after deploy

Every entry currently scored only by the recipe sits at its recipe score until Magnitu's next sync POSTs `magnitu_scores` for it. The precedence rule in `EntryScoreRepository::upsertRecipeScore()` already makes Magnitu's score win — but you have to trigger Magnitu to post.

Operational checklist after this tuning lands:

1. Confirm **`?action=magnitu_status`** reports a recent `last_sync_at` and that `scores.magnitu` is comparable to (or larger than) `scores.recipe`. If `scores.recipe ≫ scores.magnitu`, the visible badges are still mostly the deterministic fallback.
2. From the Magnitu v3 install (sibling checkout), run its **sync** (push scores). This walks all entries Magnitu has not yet scored and POSTs `magnitu_scores` back to Seismo.
3. (Optional, only if you want a clean baseline) **Settings → Magnitu → Danger zone → Clear all scores** wipes `entry_scores` entirely. The next ingest will recipe-score new entries; the next Magnitu sync will overlay ML scores. **Warning:** this is genuinely destructive — manual training labels in `magnitu_labels` are unaffected, but every "Magnitu badge ≥ alert_threshold" you've ever seen in the timeline disappears until Magnitu re-posts.

#### 4. Floor-weighted anchor concepts (Magnitu-side, shipped May 2026)

Editorial premise: *every important Swiss story carries an international / trade / IR angle.* The curated phrase list in **`magnitu v3/distiller.py`** `LEGAL_TEMPLATE_PHRASES` (e.g. `member states only` IL+0.55, `third country` IL+0.45, `equivalence decision` IL+0.35, plus EU procedural priors `implementing act` noise+0.35, `corrigendum` noise+0.30, …) is the explicit encoding of that premise. Until May 2026 those seeded weights were being silently squashed back down to `recipe_max_phrase_abs = 0.24` by `_stabilize_export_weights`, so the editorial signal effectively disappeared on its way into the exported recipe.

`distiller._apply_floor_weights()` (new) runs after the cap step and restores each `(phrase, class)` pair in `LEGAL_TEMPLATE_PHRASES` to its seeded value when the cap clipped it lower. The cap still applies to every other learned coefficient and to user-configured `legal_signal_patterns` — the floor is reserved for the curated editorial list. Symmetric across signs: diagnostic IL phrases get sharper signal, EU procedural boilerplate (`implementing act`, `corrigendum`, `annex amendment`) gets sharper noise suppression at the same time.

Effect on the PHP softmax in `RecipeScorer`, holding the rest of the recipe constant:

| Anchor phrases firing | Pre-fix relevance (0.24 cap) | Post-fix relevance (seed value, e.g. 0.55) |
|---|---|---|
| 0 (no-signal attractor) | 0.498 | 0.498 |
| 1 strong diagnostic | 0.529 | 0.575 |
| 2 strong diagnostics | 0.567 | 0.668 |
| 3 strong diagnostics | 0.602 | 0.754 |

**No operator action required.** The next Magnitu sync (or **Settings → Magnitu → Regenerate recipe**) ships a recipe with the seeded weights present; Seismo's `RecipeScorer` reads them on the following refresh tick. **Alert threshold stays at 0.60** for now — single-anchor entries still don't badge (0.575 < 0.60), two+ anchors do. Raise to 0.70 manually in **Settings → Magnitu** if Lex-heavy Highlights become noisy after a Magnitu sync cycle; lower to 0.55 if anchors aren't surfacing enough.

To roll back: delete `_apply_floor_weights()` and its single call site in `distill_recipe()` (Magnitu side); no Seismo change needed.

#### Strategic context: what these address, what still remains

The 48–52 cluster had two compounding causes; the May 2026 round resolves the second:

- The recipe is distilled from a logistic regression trained on 822 labels with `investigation_lead` represented in only 64 of them (≈ 8 %). The classifier's `f1_macro = 0.44` means the recipe weights are *correctly* small — there is no strong signal in the label set for the model to learn. **Still unresolved**; only more / better labelling (Gemini synthetic batching, manual training) moves this.
- The model's calibration step (`temperature = 2.45`) intentionally *softens* its raw probabilities to compensate for over-confidence. Seismo's recipe applies plain `T=1` softmax to the same weights, which would normally produce *more* peaked probabilities — except the weights are too small to peak anywhere.
- Curated anchor concepts (`member states only`, `eu eea`, `equivalence decision`, EU procedural priors, …) **were** the editorial-premise floor, but were being squashed to the 0.24 export cap. **Resolved by #4 above.** The LR can still lift these further when labels confirm; it can no longer suppress them below the floor.

### Export API (machine-readable)

- **`?action=export_briefing`** — Markdown digest for a time window (Bearer **`export:api_key`**, distinct from the Magnitu write key).
- **`?action=export_entries`** — JSON entries with score metadata.

Use these for **LLM briefings**, **n8n**, **Raycast**, or **cron + curl** — the same surface that replaces the old 0.4 **“AI view”** HTML page. See in-app **About** (`?action=about`) for a short user-facing summary.

### Operations & safety

- **Master refresh** — Web **Refresh** (Timeline or **Settings → Diagnostics**) and **`refresh_cron.php`** share **`RefreshAllService::runAll()`** so cron and the UI stay aligned (plugins + core fetchers + retention hooks as implemented).
- **RSS & scraper (default)** — Ingest runs in **chunks** (a limited number of feeds per cron tick; manual refresh loops chunks for a short wall-clock budget). Cycle progress is stored under `refresh_chunk:*` keys in **`system_config`**. A completed rotation is what writes **`plugin_run_log`** for `core:rss` / `core:scraper`, so module **throttles** still apply between full cycles. **Legacy single-pass** for those two fetchers is opt-in under **Settings → General**.
- **Cron overlap & web refresh** — **`refresh_cron.php`** uses **`GET_LOCK`** / **`RELEASE_LOCK`** (via **`CronMutexRepository`**, name scoped by database) so only **one** master-cron tick runs per DB at a time; a second overlapping process logs *skipped* and exits **0**. **Diagnostics “Refresh all”**, **Feeds**/**Scraper** module refresh, and per-fetcher refresh for **`core:rss`** / **`core:scraper`** acquire the **same** lock via **`RefreshAllService`**, so manual refresh cannot race cron on chunked RSS/scraper cursor rows.
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
- **First install without a file yet:** open **`?action=configuration`** in the browser — it tests credentials, then writes **`config.local.php`** or shows a **copy-paste** block if the directory is not writable (never loosen permissions to `0777`). Legacy **`?action=setup`** redirects here.

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

If the scheduler fires **more often than a single tick can finish** (common with **every-minute** crons and slow networks), a second PHP process **does not** run the pipeline in parallel: it tries the advisory lock, prints that the tick was skipped, and exits **0** so your mailer is not spammed with false failures. RSS/scraper **chunking** (see above) is what keeps each tick short enough for typical shared-host `max_execution_time` limits.

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
| `?action=settings` | Global settings (Magnitu, **Mail / IMAP** on mothership, retention, satellites, **Diagnostics** tab, **General** tab for migrate key + admin password, UI defaults). |
| `?action=configuration` | **Mothership:** database probe + starter `config.local.php` (or copy-paste). Public only while `config.local.php` is missing; then same as other pages. Legacy `?action=setup` redirects here. |
| `?action=settings&tab=diagnostics` | Plugin + core fetcher status, throttles, manual refresh & dry-run test fetch (mothership; last tab under Settings). |
| `?action=diagnostics` | **Legacy** URL — **303** redirect to `?action=settings&tab=diagnostics`. |
| `?action=feeds` / `scraper` / `mail` | Module-owned **Items \| Sources** admin pattern. |
| `?action=lex` / `leg` | Lex and Leg pages with per-source refresh & config. |

Full **Magnitu / export** request shapes live in **`.cursor/rules/magnitu-integration.mdc`** (authoritative contract for both repos).

---

## Repository layout

```
index.php              # Front controller: session, router, AuthGate
bootstrap.php          # config.local.php, UTC, Composer autoload, Seismo\ autoload, helpers
refresh_cron.php       # CLI-only master refresh (+ retention tail); MySQL lock against overlap
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

**Status (2026-05-11):** the **0.5 consolidation is closed**. Every slice in **[`docs/consolidation-plan.md`](docs/consolidation-plan.md)** (Slices 0 → 10) shipped; subsequent work on this tree is **operational maintenance and product follow-ups**, not unfinished rewrite work. The version line at the top of this README tracks ongoing point releases (currently **0.5.3**).

If you are **diffing behaviour against 0.4** or tracing **where a feature moved**, use **[`README-REORG.md`](README-REORG.md)** — a chronological **migration log** (newest entries first), not the primary product readme. Slice-numbered entries in that log are the consolidation history; entries above its boundary banner are post-consolidation maintenance. High-level goals and remaining product follow-ups (none of them blocking) live in **[`docs/consolidation-plan.md`](docs/consolidation-plan.md)**.

---

## Further reading

- **[`docs/setup-wizard-notes.md`](docs/setup-wizard-notes.md)** — shared-host pitfalls, migrate URL patterns, wizard notes.
- **[`.cursor/rules/feature-development-architecture.mdc`](.cursor/rules/feature-development-architecture.mdc)** — where new code belongs (controllers vs repositories vs plugins).
