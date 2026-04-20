<?php

declare(strict_types=1);

namespace Seismo\Repository;

/**
 * Dashboard tag-filter state. Default = show everything (no query params).
 *
 * **Exclusion model (index UI):** pills start “on”; turning a pill off appends
 * to `efc` / `elx` / `eet` (comma lists). `ecal=1` hides Leg / `calendar_event`
 * rows; `ejus=1` hides Swiss case-law Lex sources (`ch_bger`, `ch_bge`, `ch_bvger`).
 *
 * **Selection:** `sel=none` shows an intentionally empty timeline (no DB merge).
 *
 * **Legacy inclusion** (`fc`, `fk`, `lx`, `etag`) is still parsed for old links.
 *
 * Keep in sync with {@see DashboardController} and {@see FavouriteController::RETURN_QUERY_ALLOW}.
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
     * @param list<string> $excludedFeedCategories Dashboard: exclude these feed categories.
     * @param list<string> $excludedLexSources     Dashboard: exclude these Lex `source` values.
     * @param list<string> $excludedEmailTags      Dashboard: exclude these sender tags.
     */
    public function __construct(
        public readonly bool $selectionNone = false,
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

    public function isActive(): bool
    {
        return $this->selectionNone
            || $this->feedCategories !== []
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
        $sel = isset($get['sel']) ? strtolower(trim((string)$get['sel'])) : '';

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
            selectionNone: ($sel === 'none'),
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
