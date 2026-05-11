# Proposal — Watchlist tab (deterministic rule alerts)

**Status:** Parked idea, 2026-05-11. Not scheduled, not promised. Not part of the
Slice 1–10 consolidation. Revisit when (a) journalists are actively using Seismo
and asking "how do I make sure I never miss X?" or (b) you want a deterministic
floor under the recipe + ML stack that does not depend on retraining.

**Context for future-me.** Today Seismo ranks the timeline with two scorers:
the deterministic `RecipeScorer` (keyword + softmax → relevance in `[0, 1]`,
plus a `predicted_label`) and Magnitu v3's ML overlay (ML scores beat recipe
via the precedence rule in `EntryScoreRepository::upsertRecipeScore()`). Both
are "fuzzy guesses at general interestingness." Neither lets a journalist say
*"tell me, with certainty, the moment X happens."*

This proposal adds that third capability as a **separate user-facing tab**,
without changing how the timeline or highlights work.

---

## What it is

A **Watchlist** tab where a journalist defines named rules. Any entry — feed,
email, Lex, Leg — that matches an enabled rule shows up on that tab, grouped
by rule, newest-first. Each entry shows which rule(s) fired and what the rule
was looking for.

It is **not** a replacement for the recipe scorer or for Magnitu. It is the
"saved searches with teeth" feature journalists expect from any monitoring
tool. Rules are explicit, deterministic, and self-documenting — a journalist
can look at their own watchlist and see, in one screen, what they have told
Seismo to flag.

### What a rule is

Three fields, nothing fancy:

| Field | Type | Example |
|---|---|---|
| `name` | short string | `FINMA enforcement` |
| `pattern` | regex or phrase set | `/\bFINMA\b/iu AND /(Verfahren\|Sanktion\|Enforcement\|Bussen)/iu` |
| `enabled` | bool | `true` |

Optional later: `scope` (which entry families to evaluate against — e.g. "Lex
only", "feeds + emails only"), `priority` (1–3, only affects rendering order
on the tab), `expires_at` (auto-disable after date for short-term watches).

`pattern_type` distinguishes free-text shapes:

- `phrase` — plain phrase, case-insensitive containment match. Cheapest.
- `regex` — single PHP regex, compiled once per refresh tick.
- `compound` — boolean of phrases/regexes joined by `AND` / `OR` / `NOT`.

Compound is the only one a journalist usually wants. Start with `phrase` and
`compound` only; `regex` is for admin-power-user rules. The UI never asks the
journalist to write a PCRE — the compound builder produces it.

---

## Architecture (when it gets built)

### Storage (local, never `entryTable()`-wrapped)

Two new tables on every instance — they describe *this journalist's* alerts,
not entry data. Both prune-safe via `RetentionService`.

```sql
CREATE TABLE watchlist_rules (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    pattern     TEXT NOT NULL,           -- serialised compound, or raw regex/phrase
    pattern_type ENUM('phrase','regex','compound') NOT NULL DEFAULT 'phrase',
    scope       VARCHAR(60) DEFAULT 'all', -- 'all' | 'feeds' | 'lex' | 'mail' | 'leg' | csv
    enabled     TINYINT(1) NOT NULL DEFAULT 1,
    priority    TINYINT NOT NULL DEFAULT 2,
    expires_at  DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY enabled_priority (enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE watchlist_hits (
    rule_id     INT UNSIGNED NOT NULL,
    entry_type  ENUM('feed_item','email','lex_item','calendar_event') NOT NULL,
    entry_id    INT UNSIGNED NOT NULL,
    matched_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    matched_snippet VARCHAR(500) NULL,  -- ~160 chars of context around the first match
    PRIMARY KEY (rule_id, entry_type, entry_id),
    KEY rule_recent (rule_id, matched_at),
    KEY recent_global (matched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Both stay **local on every instance** — mothership has its own, each satellite
has its own. No `entryTable()` wrapping. Watchlists are per-journalist /
per-instance state, like `entry_favourites`, `entry_scores`, `magnitu_labels`.

### Evaluation

One new service, `Seismo\Core\Watchlist\RuleEvaluator`. Hooks into
`RefreshAllService::runAll()` as the last step before `RetentionService`:

```
RefreshAllService::runAll()
  ↓
  ... existing plugin + core fetchers ...
  ↓
  ScoringService::rescoreStoredRecipeBestEffort()  (existing)
  ↓
  RuleEvaluator::evaluateNewEntries()              (new — this proposal)
  ↓
  RetentionService::run()                          (existing)
```

`evaluateNewEntries()` walks rows new since `MAX(matched_at)` per family,
checks each enabled rule, writes one row to `watchlist_hits` per match.
Cheap: regex on already-loaded text, no embeddings, no HTTP. A 4h cron tick
on hundreds of new entries is sub-second.

A `WatchlistRepository` owns all SQL — list/save/delete rules, list hits,
match-count per rule, pruning. Standard 0.5 shape.

### Web surface

| Route | Method | Purpose |
|---|---|---|
| `?action=watchlist` | GET | Tab. Lists hits grouped by rule, newest-first, with rule definitions in a sidebar / disclosure. Reuses `dashboard_entry_loop.php` for entry cards (cards are non-negotiable). |
| `?action=watchlist_rules` | GET | List/edit rules. CRUD UI with compound builder. |
| `?action=watchlist_save` | POST | Create/update rule. CSRF. |
| `?action=watchlist_delete` | POST | Delete rule. CSRF. |
| `?action=watchlist_toggle` | POST | Enable/disable rule. CSRF. |

Nav drawer entry between Highlights and Lex. Counter badge with unseen hits
since last visit, like Gmail. (Optional — easy to bolt on later.)

### Auth

All session-auth gated when `SEISMO_ADMIN_PASSWORD_HASH` is set, same as the
rest of the admin surface (`auth-dormant-by-default.mdc`). Bearer tokens
do **not** apply here — this is human-facing only.

### Export integration (optional follow-up)

The export API stays focused on scored entries. A future endpoint
`?action=export_watchlist_hits&rule=<name>&since=<iso>` (Bearer `export:api_key`)
would let an LLM briefing or n8n flow consume named-rule hits as a structured
stream. Defer until someone asks.

---

## Why this is the right shape (and what it is *not*)

### Three different jobs, three different surfaces

| Question the journalist has | Surface that answers it |
|---|---|
| "What's generally relevant on my beat today?" | **Timeline** — recipe + Magnitu ranking, newest-first. |
| "What scored above my alert threshold across all sources?" | **Highlights** (`?action=magnitu`) — score-gated stream. |
| "Tell me the *moment* this specific thing happens." | **Watchlist** (this proposal) — explicit, deterministic, no training. |

Today only the first two exist. Journalists with a beat (Bundeshaus, FINMA,
EU regulation, Bildung, cantonal politics, Klimapolitik, …) almost always
have a mental list of "the day X happens, I want to know" patterns. Today
they have to either trust the recipe will rank it high (it often does not)
or skim the timeline manually. Watchlist is the missing third surface.

### Why rules are not "the new scorer"

Earlier discussion floated replacing `RecipeScorer` with a rule engine. That
was wrong for the broader product. A journalist tool needs **both** fuzzy
ranking (for the general feed) and sharp alerts (for explicit watches). The
fuzzy side is what ML and recipe are good at; the sharp side is what rules
are good at. Mixing them confuses both jobs:

- A rule that pushes the timeline relevance to 1.0 makes the rest of the
  timeline noise.
- A scorer that aggregates rule signals into one float loses the per-rule
  attribution that makes Watchlist debuggable.

Keep them separate. The Watchlist tab is **additive** — it does not write to
`entry_scores`, does not affect the recipe, does not interact with Magnitu's
contract. The fuzzy stack on the timeline can be tuned (or replaced, or left
alone) independently.

### Why rules also seed better ML training

Every Watchlist hit a journalist clicks through and finds useful is, by
definition, a candidate `investigation_lead` / `important` training label.
The Label tab can offer "Label this Watchlist hit" as one-click, with the
rule name as a pre-filled reasoning note. Rules → labels → Magnitu → recipe.
The two loops reinforce, they do not compete.

This also addresses the "ML cold start" problem — a fresh install with no
labels still gives the journalist *something useful immediately* via their
own rules, while the model accumulates training data in the background.

---

## What it costs (rough sizing)

| Piece | Estimated effort |
|---|---|
| `watchlist_rules`, `watchlist_hits` migration | ½ day |
| `WatchlistRepository` (SQL only, satellite-local) | ½ day |
| `RuleEvaluator` (phrase + compound; regex as power-user) | 1 day |
| Hook into `RefreshAllService::runAll()` | ½ day |
| `WatchlistController` (list, save, delete, toggle) | ½ day |
| `views/watchlist.php` + rule editor view | 1 day |
| Compound rule builder UI (chips: any-of, all-of, not-any-of) | 1 day |
| Tests (Repository, RuleEvaluator with multilingual fixtures) | ½ day |
| Nav drawer + Settings → Watchlist retention slot | ½ day |
| **Total** | **~6 working days** |

No new dependencies. No schema changes outside the two tables. No change to
the Magnitu API. No change to the export API. No change to the timeline or
Highlights.

---

## Open product questions (decide before building)

These do not block parking the idea. They are the things to nail down at the
start of the work, not in the middle.

1. **Per-user or per-instance?** The proposal as written is per-instance
   (one watchlist for the whole Seismo). If multiple journalists share an
   install, do we want per-user rules? If yes, multi-user auth has to land
   first — currently the dormant auth model is single-admin. Realistic
   starting point: per-instance is fine; revisit when multi-user lands.

2. **Hit retention.** Match rows accumulate forever otherwise. Sensible
   default: prune `watchlist_hits` after 365d (or whenever the matched
   entry is pruned by family retention — join on `entry_type` + `entry_id`).
   Rules themselves are never auto-pruned.

3. **Rendering on the timeline.** Should a Watchlist match also surface as a
   subtle badge on the main timeline card ("matched: FINMA enforcement"),
   or stay isolated on its own tab? Lean: subtle badge, opt-in per rule via
   a `surface_on_timeline` boolean. Decide once people use it.

4. **Compound builder UX.** Two reasonable shapes:
   - Free-text "fix it yourself" — power users only.
   - Chip-based AND/OR/NOT (Gmail-style). Friendlier; ~⅓ of total build
     effort because of the form interactions.
   Recommend chip-based.

5. **Multilingual matching.** Phrases stored as-is; the evaluator lowercases
   both sides before comparing. Regex rules carry `/iu` flags. No automatic
   cross-language expansion (do not reintroduce the `swiss_dictionary` style
   coupling here — let the journalist write the four-language alternation
   themselves; it is explicit and readable).

---

## Non-goals (do not scope-creep into this)

- Replacing `RecipeScorer`. Different conversation, separate from this.
- Webhook / email / Slack notifications. The web tab is enough for v1.
- Cross-instance rule sync between mothership and satellites. Each instance
  has its own watchlist (same as labels, same as favourites).
- A rule-marketplace ("share your rule list"). Future, only if obvious demand.
- Importing rules from Google Alerts / RSS query strings. Same — future.

---

## Pointer back to the broader discussion

The broader review (over-engineered scorer vs the journalist use case) lives
in conversation history, not in this repo. Two adjacent items from that
discussion to consider alongside this one:

1. **The recipe scoring 48–52 cluster.** Magnitu-side fix anticipated in
   `README.md` "Scoring tuning (May 2026)" — **as of 2026-05-11 has not
   shipped** in Magnitu v3 (`distiller.py`'s `_stabilize_export_weights`
   still caps anchor phrases at `recipe_max_phrase_abs = 0.24`, overriding
   the higher seed weights in `LEGAL_TEMPLATE_PHRASES`). Independent of
   this proposal.

2. **Enrichment metadata** (jurisdiction tag, commencement-date extractor,
   sector tag) — also independent of this proposal, but synergetic: rule
   patterns can reference enricher output (`jurisdiction = IT AND
   commencement_in_days < 30`) once those fields exist, which is much
   nicer than free-text regex on body content.

Neither is a prerequisite for Watchlist.
