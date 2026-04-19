<?php

declare(strict_types=1);

namespace Seismo\Repository;

/**
 * Dashboard tag-filter state (Slice 4). Parsed from GET — keep in sync with
 * {@see DashboardController} and {@see FavouriteController::RETURN_QUERY_ALLOW}.
 */
final class TimelineFilter
{
    /**
     * @param list<string> $lexSources Lex `source` values (e.g. ch, eu, fr).
     */
    public function __construct(
        public readonly bool $includeCalendar = true,
        public readonly ?string $feedCategory = null,
        public readonly ?string $feedSourceKind = null,
        public readonly array $lexSources = [],
        public readonly ?string $emailTag = null,
    ) {
    }

    public function isActive(): bool
    {
        return !$this->includeCalendar
            || $this->feedCategory !== null
            || $this->feedSourceKind !== null
            || $this->lexSources !== []
            || $this->emailTag !== null;
    }

    /**
     * @param array<string, mixed> $get Typically $_GET
     */
    public static function fromQueryArray(array $get): self
    {
        $nocal = isset($get['nocal']) && (string)$get['nocal'] === '1';
        $fc    = isset($get['fc']) ? trim((string)$get['fc']) : '';
        $fk    = isset($get['fk']) ? trim((string)$get['fk']) : '';
        $lx    = isset($get['lx']) ? trim((string)$get['lx']) : '';
        $etag  = isset($get['etag']) ? trim((string)$get['etag']) : '';

        $lexList = [];
        if ($lx !== '') {
            foreach (explode(',', $lx) as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $lexList[] = $p;
                }
            }
        }

        $fkNorm = null;
        if (in_array($fk, ['rss', 'substack', 'scraper'], true)) {
            $fkNorm = $fk;
        }

        return new self(
            includeCalendar: !$nocal,
            feedCategory: $fc !== '' ? $fc : null,
            feedSourceKind: $fkNorm,
            lexSources: $lexList,
            emailTag: $etag !== '' ? $etag : null,
        );
    }
}
