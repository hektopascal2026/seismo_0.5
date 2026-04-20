<?php

declare(strict_types=1);

namespace Seismo\Repository;

/**
 * Dashboard tag-filter state. Default = show everything (no query params).
 *
 * **Per-pill OFF lists:** `efc` / `elx` / `eet` are comma-separated tokens that
 * are turned **off** (excluded from SQL). Empty list = every pill in that row
 * is **on**. Pills are independent: toggling one only changes its list.
 *
 * **Leg / Jus:** `ecal=1` hides calendar rows; `ejus=1` hides Jus Lex sources.
 *
 * **Selection All (UI):** clears all exclusions + legacy params.
 * **Selection None (UI):** sets `efc`/`elx`/`eet` to every known pill option,
 * plus `ecal=1` and `ejus=1`, so the timeline is empty until the user turns
 * pills back on.
 *
 * **Legacy inclusion** (`fc`, `fk`, `lx`, `etag`) is still parsed for old links.
 *
 * Keep in sync with {@see DashboardController} and {@see FavouriteController::RETURN_QUERY_ALLOW}
 * (dashboard filter query keys only).
 */
final class TimelineFilter
{
    private const FK_ALLOWED = ['rss', 'substack', 'scraper'];

    /** Lex sources treated as “Jus” on the Leg / Jus filter row (not Lex pills). */
    public const JUS_LEX_SOURCES = ['ch_bger', 'ch_bge', 'ch_bvger'];

    /**
     * @param list<string> $feedCategories       Legacy: include only these `feeds.category` values.
     * @param list<string> $feedSourceKinds      Legacy: rss|substack|scraper OR.
     * @param list<string> $lexSources           Legacy: Lex `source` IN (…).
     * @param list<string> $emailTags            Legacy: sender tag IN (…).
     * @param list<string> $excludedFeedCategories Dashboard: these feed categories are OFF.
     * @param list<string> $excludedLexSources     Dashboard: these Lex sources are OFF.
     * @param list<string> $excludedEmailTags      Dashboard: these sender tags are OFF.
     */
    public function __construct(
        public readonly array $feedCategories = [],
        public readonly array $feedSourceKinds = [],
        public readonly array $lexSources = [],
        public readonly array $emailTags = [],
        public readonly array $excludedFeedCategories = [],
        public readonly array $excludedLexSources = [],
        public readonly array $excludedEmailTags = [],
        public readonly bool $excludeCalendar = false,
        public readonly bool $excludeJusLex = false,
    ) {
    }

    /**
     * Any filter that narrows the timeline (exclusions, Leg/Jus off, legacy).
     */
    public function isActive(): bool
    {
        return $this->feedCategories !== []
            || $this->feedSourceKinds !== []
            || $this->lexSources !== []
            || $this->emailTags !== []
            || $this->excludedFeedCategories !== []
            || $this->excludedLexSources !== []
            || $this->excludedEmailTags !== []
            || $this->excludeCalendar
            || $this->excludeJusLex;
    }

    /**
     * Default dashboard pill state: no exclusions, Leg + Jus on, no legacy.
     */
    public function dashboardPillsAllOn(): bool
    {
        return $this->excludedFeedCategories === []
            && $this->excludedLexSources === []
            && $this->excludedEmailTags === []
            && !$this->excludeCalendar
            && !$this->excludeJusLex
            && $this->feedCategories === []
            && $this->feedSourceKinds === []
            && $this->lexSources === []
            && $this->emailTags === [];
    }

    /**
     * True when exclusions match “everything off” for the current pill option sets.
     *
     * @param array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>} $pillOpts
     */
    public function dashboardPillsAllOff(array $pillOpts): bool
    {
        if (!$this->excludeCalendar || !$this->excludeJusLex) {
            return false;
        }
        if (!$this->legacyFiltersEmpty()) {
            return false;
        }
        $feeds = $pillOpts['feed_categories'] ?? [];
        $lex   = $pillOpts['lex_sources'] ?? [];
        $em    = $pillOpts['email_tags'] ?? [];

        return self::sameStringSet($feeds, $this->excludedFeedCategories)
            && self::sameStringSet($lex, $this->excludedLexSources)
            && self::sameStringSet($em, $this->excludedEmailTags);
    }

    private function legacyFiltersEmpty(): bool
    {
        return $this->feedCategories === []
            && $this->feedSourceKinds === []
            && $this->lexSources === []
            && $this->emailTags === [];
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function sameStringSet(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Lex sources excluded per-pill plus optional Jus trio when `ejus=1`.
     *
     * @return list<string>
     */
    public function effectiveExcludedLexSources(): array
    {
        $x = $this->excludedLexSources;
        if ($this->excludeJusLex) {
            foreach (self::JUS_LEX_SOURCES as $j) {
                if (!in_array($j, $x, true)) {
                    $x[] = $j;
                }
            }
        }

        return array_values(array_unique($x));
    }

    /**
     * @param array<string, mixed> $get Typically $_GET
     */
    public static function fromQueryArray(array $get): self
    {
        $fcList   = self::parseListParam($get['fc'] ?? null);
        $fkList   = self::normalizeFkList(self::parseListParam($get['fk'] ?? null));
        $lxList   = self::parseListParam($get['lx'] ?? null);
        $etagList = self::parseListParam($get['etag'] ?? null);

        $efcList = self::parseListParam($get['efc'] ?? null);
        $elxList = self::parseListParam($get['elx'] ?? null);
        $eetList = self::parseListParam($get['eet'] ?? null);

        $ecalRaw = isset($get['ecal']) ? trim((string)$get['ecal']) : '';
        $ejusRaw = isset($get['ejus']) ? trim((string)$get['ejus']) : '';

        return new self(
            feedCategories: $fcList,
            feedSourceKinds: $fkList,
            lexSources: $lxList,
            emailTags: $etagList,
            excludedFeedCategories: $efcList,
            excludedLexSources: $elxList,
            excludedEmailTags: $eetList,
            excludeCalendar: ($ecalRaw === '1' || strtolower($ecalRaw) === 'true'),
            excludeJusLex: ($ejusRaw === '1' || strtolower($ejusRaw) === 'true'),
        );
    }

    /**
     * @return list<string>
     */
    private static function parseListParam(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $v) {
                if (!is_scalar($v)) {
                    continue;
                }
                $s = trim((string)$v);
                if ($s !== '') {
                    $out[] = $s;
                }
            }

            return array_values(array_unique($out));
        }
        $s = trim((string)$raw);
        if ($s === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $s) as $p) {
            $p = trim($p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $parsed
     * @return list<string>
     */
    private static function normalizeFkList(array $parsed): array
    {
        $out = [];
        foreach ($parsed as $p) {
            if (in_array($p, self::FK_ALLOWED, true)) {
                $out[] = $p;
            }
        }
        $out = array_values(array_unique($out));
        if (count($out) === count(self::FK_ALLOWED)) {
            return [];
        }

        return $out;
    }
}
