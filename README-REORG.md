# Seismo 0.5 — reorganisation log

Technical companion to `README.md`, written **live** during the 0.4 → 0.5 consolidation. Explains, slice by slice, what moved, why, and how the new wiring works. The audience is anyone (including future-me) who knows 0.4 and needs to find where something went in 0.5.

- Entries are **newest on top**.
- Every entry follows the same four-part shape: **Why**, **What moved**, **New wiring**, **Gotchas**.
- References use **file paths**, not line numbers (they drift).
- Companion doc for users (in-app about page, `views/about.php`) is left alone until the consolidation is done — see `.cursor/rules/documentation-strategy.mdc`.

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
