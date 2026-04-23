# Newsbridge (static RSS for Seismo)

This directory holds **config + generated** RSS files that Seismo ingests as normal RSS `feeds` (same as before when they lived under staging).

1. **Copy** `config.example.json` to `config.json` and set:
   - Your public base URL in each `self_link` and in `channel.link` (use `getBasePath()`-style paths on your host, e.g. `https://www.example.org/seismo/`).
   - The `sources` arrays: same RSS URLs you used on staging (or your curated list).

2. **Run once (or on cron):**
   ```bash
   php /path/to/seismo_0.5/newsbridge/newsbridge_cron.php
   ```
   This writes `newsbridge/feeds/*.xml` next to this repo.

3. **In Seismo → Feeds**, set each of the four feed rows to the **new** URLs, e.g.:
   - `https://<host>/seismo/newsbridge/feeds/top-ch.xml`
   - … `ch-en.xml`, `ch-de.xml`, `ch-fr.xml`  
   Remove any `/seismo-staging/newsbridge/` URLs.

4. **Cron** (separate from `refresh_cron.php`):
   ```cron
   */20 * * * * /usr/bin/php /path/to/seismo_0.5/newsbridge/newsbridge_cron.php
   ```

`config.json` is gitignored; ship `config.example.json` in the repo.

Optional `language` per output: `de`, `fr`, `en` (heuristic filter on title+description+content) or `any`/omit. Prefer tuning **sources** per file for predictable results.
