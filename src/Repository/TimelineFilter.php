<?php

declare(strict_types=1);

namespace Seismo\Repository;

/**
 * Dashboard tag-filter state (Slice 4). Parsed from GET — keep in sync with
 * {@see DashboardController} and {@see FavouriteController::RETURN_QUERY_ALLOW}.
 *
 * Multi-select: `fc`, `fk`, `lx`, `etag` accept comma-separated tokens (or a
 * single token). `leg=1` limits the merged timeline to Leg / `calendar_event`
 * rows only (other filter params are ignored until Leg-only is cleared).
 */
final class TimelineFilter
{
    private const FK_ALLOWED = ['rss', 'substack', 'scraper'];

    /**
     * @param list<string> $feedCategories `feeds.category` values (OR).
     * @param list<string> $feedSourceKinds subset of rss|substack|scraper (OR).
     * @param list<string> $lexSources Lex `source` values (OR).
     * @param list<string> $emailTags `sender_tags.tag` values (OR).
     */
    public function __construct(
        public readonly array $feedCategories = [],
        public readonly array $feedSourceKinds = [],
        public readonly array $lexSources = [],
        public readonly array $emailTags = [],
        public readonly bool $calendarOnly = false,
    ) {
    }

    public function isActive(): bool
    {
        return $this->feedCategories !== []
            || $this->feedSourceKinds !== []
            || $this->lexSources !== []
            || $this->emailTags !== []
            || $this->calendarOnly;
    }

    /**
     * @param array<string, mixed> $get Typically $_GET
     */
    public static function fromQueryArray(array $get): self
    {
        $fcList = self::parseListParam($get['fc'] ?? null);
        $fkList = self::normalizeFkList(self::parseListParam($get['fk'] ?? null));
        $lxList = self::parseListParam($get['lx'] ?? null);
        $etagList = self::parseListParam($get['etag'] ?? null);

        $legRaw = isset($get['leg']) ? trim((string)$get['leg']) : '';

        return new self(
            feedCategories: $fcList,
            feedSourceKinds: $fkList,
            lexSources: $lxList,
            emailTags: $etagList,
            calendarOnly: ($legRaw === '1' || strtolower($legRaw) === 'true'),
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
