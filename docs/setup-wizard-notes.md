# Setup wizard — working notes

Scratchpad for a future “first run” experience after a user uploads Seismo to webspace. **Append** items as we consolidate 0.5; keep entries short and actionable.

## Already identified

### Portability

- **`getBasePath()`** — must remain the single source for web-relative paths (subfolder installs).
- **Downloaded config files** — cron `config.php` (next to `refresh_cron.php`) and fetcher configs embed **filesystem paths** from the server at download time; re-run or reconfigure after moving hosts.

### Refresh vs cron (user expectations)

- **Web “Refresh all”** uses `refreshAllSources()` in `controllers/dashboard.php` (feeds, email, Lex/Jus, Leg/parliament_ch when enabled, Magnitu rescore).
- **`refresh_cron.php` (0.4)** duplicates the loop manually and **does not** call `refreshAllSources()` — as of consolidation start it **omits the Leg / parliament_ch fetch** that the web button runs. Fix by having cron delegate to `refreshAllSources()` (or shared helper) so behaviour matches.
- **Mail** and **scraper** use **separate** CLI scripts and crons — not confused with the main refresh loop.

### Wizard could later check

- PHP version and extensions (cURL, IMAP, PDO MySQL, etc.).
- `config.local.php` present and DB reachable.
- Optional: writable JSON config paths; `.htaccess` / rewrite if applicable.
- Generate or validate cron suggestions with **current** install path.

### Auth onboarding (Slice 3)

- Wizard should offer a "Set an admin password" step. On submit: compute `password_hash($input, PASSWORD_DEFAULT)` server-side, write the result to `config.local.php` as `SEISMO_ADMIN_PASSWORD_HASH`, never log or echo the plaintext. User can skip this step and the app runs unauthenticated — that's a supported default, not a warning.
- If the wizard can't write to `config.local.php` (read-only filesystem on some shared hosts), display the hash and the exact `define(...)` line for the user to paste manually.

### First-run / post-upload (Slice 0 complete)

- After upload, operator creates `config.local.php` on the server (never committed).

**No SSH / no PHP CLI (URL-only hosting — typical shared webspace)**

1. In `config.local.php`, set **`SEISMO_MIGRATE_KEY`** to a long random string (generate on your laptop: `php -r "echo bin2hex(random_bytes(32));"` and paste the hex).
2. Upload `config.local.php` and ensure **`docs/db-schema.sql`** is on the server (same folder layout as in the repo — the migrator reads it at runtime).
3. Open **once** in the browser (replace host, path, and secret):

   `https://www.example.org/seismo/?action=migrate&key=YOUR_SECRET`

4. Expect **plain text**: current schema version, then either **“Nothing to do — schema is already at version 17”** (if the DB was already migrated by 0.4) or lines showing migration 17 applied. **No HTML** — if you see a login page or 404, the URL path or key is wrong.
5. Optional: remove or comment out `SEISMO_MIGRATE_KEY` after migrations are done so the URL cannot be called anymore.

**With SSH or host “Run PHP script” (CLI available)**

- `php migrate.php` — apply pending migrations.
- `php migrate.php --status` — print version only, no changes.

On an empty database, migration applies `docs/db-schema.sql` and sets `schema_version` to 17. On a database already used by Seismo 0.4, expect **no destructive operations** — mostly **“Nothing to do”** when already at 17.

### Retention onboarding (Slice 5a)

- Wizard lands on retention defaults (`feed_items` 180d, `emails` 180d, `lex_items` unlimited, `calendar_events` unlimited). Shows current row counts and a dry-run preview of what would be deleted. No pruning runs until the user explicitly opts in.
- Flag that Magnitu-labelled rows are always preserved — important for users who've already started training.
