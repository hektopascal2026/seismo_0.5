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

### Retention onboarding (Slice 5a)

- Wizard lands on retention defaults (`feed_items` 180d, `emails` 180d, `lex_items` unlimited, `calendar_events` unlimited). Shows current row counts and a dry-run preview of what would be deleted. No pruning runs until the user explicitly opts in.
- Flag that Magnitu-labelled rows are always preserved — important for users who've already started training.
