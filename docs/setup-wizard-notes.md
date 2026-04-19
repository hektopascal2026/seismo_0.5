# Setup wizard — working notes

Scratchpad for a future “first run” experience after a user uploads Seismo to webspace. **Append** items as we consolidate 0.5; keep entries short and actionable.

## Already identified

### Portability

- **`getBasePath()`** — must remain the single source for web-relative paths (subfolder installs).
- **Downloaded config files** — cron `config.php` (next to `refresh_cron.php`) and fetcher configs embed **filesystem paths** from the server at download time; re-run or reconfigure after moving hosts.

### Refresh vs cron (user expectations)

- **Web “Refresh all”** uses `refreshAllSources()` in `controllers/dashboard.php` (feeds, email, Lex/Jus, Leg/parliament_ch when enabled, Magnitu rescore).
- **`refresh_cron.php`** should match that pipeline; if it diverges, document clearly or unify implementations so “automatic refresh” and the button mean the same thing.
- **Mail** and **scraper** use **separate** CLI scripts and crons — not confused with the main refresh loop.

### Wizard could later check

- PHP version and extensions (cURL, IMAP, PDO MySQL, etc.).
- `config.local.php` present and DB reachable.
- Optional: writable JSON config paths; `.htaccess` / rewrite if applicable.
- Generate or validate cron suggestions with **current** install path.
