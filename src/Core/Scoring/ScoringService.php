<?php
/**
 * Orchestrates recipe-based rescoring across every entry family.
 *
 * The service is stateless beyond its injected dependencies and holds NO
 * SQL — unscored-row lookups live in {@see EntryScoreRepository}, score
 * writes live in the same repository. A single {@see rescoreAll()} call
 * corresponds to what 0.4's `magnituRescore()` did:
 *
 *   1. Ask {@see EntryScoreRepository} for feed_items, lex_items, emails,
 *      calendar_events that do NOT yet carry a `score_source = 'magnitu'`
 *      row (per-family methods, bounded by {@see self::BATCH_LIMIT}).
 *   2. Compute the recipe score via {@see RecipeScorer::score()} — a pure
 *      function with no I/O.
 *   3. Upsert via {@see EntryScoreRepository::upsertRecipeScore()} — which
 *      preserves any prior Magnitu score per the precedence rule.
 *
 * The per-family batch size is capped at {@see self::BATCH_LIMIT}. Larger
 * fleets are handled by subsequent refresh cycles; this keeps memory
 * bounded on shared hosts and avoids long-running request timeouts for
 * the `magnitu_recipe` POST.
 *
 * Leg (`calendar_event`) is rescored here for recipe backfill until Magnitu
 * overwrites with ML scores.
 */

declare(strict_types=1);

namespace Seismo\Core\Scoring;

use PDOException;
use Seismo\Repository\EntryScoreRepository;

final class ScoringService
{
    /**
     * Max rows rescored per family per `rescoreAll()` call. Must stay
     * <= {@see EntryScoreRepository::MAX_UNSCORED_LIMIT}; the repo clamps
     * silently if we ever ask for more, so the two stay in sync even if
     * someone edits only one.
     */
    public const BATCH_LIMIT = 500;

    public function __construct(
        private EntryScoreRepository $scores,
    ) {
    }

    /**
     * @param array<string, mixed> $recipe Decoded recipe JSON (keywords, …).
     * @return array{feed_items:int, lex_items:int, emails:int, calendar_events:int}
     */
    public function rescoreAll(array $recipe): array
    {
        if ($recipe === [] || empty($recipe['keywords'])) {
            return ['feed_items' => 0, 'lex_items' => 0, 'emails' => 0, 'calendar_events' => 0];
        }

        $version = (int)($recipe['version'] ?? 0);

        return [
            'feed_items'      => $this->rescoreFeedItems($recipe, $version),
            'lex_items'       => $this->rescoreLexItems($recipe, $version),
            'emails'          => $this->rescoreEmails($recipe, $version),
            'calendar_events' => $this->rescoreCalendarEvents($recipe, $version),
        ];
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function rescoreFeedItems(array $recipe, int $version): int
    {
        $rows = $this->scores->getUnscoredFeedItems(self::BATCH_LIMIT);
        $done = 0;
        foreach ($rows as $row) {
            $st = (string)($row['source_type'] ?? 'rss');
            $sourceType = in_array($st, ['substack', 'scraper'], true) ? $st : 'rss';
            $body = (string)(($row['content'] !== null && $row['content'] !== '')
                ? $row['content']
                : ($row['description'] ?? ''));

            $result = RecipeScorer::score($recipe, (string)($row['title'] ?? ''), $body, $sourceType);
            if ($result === null) {
                continue;
            }
            if ($this->writeScore('feed_item', (int)$row['id'], $result, $version)) {
                $done++;
            }
        }
        return $done;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function rescoreLexItems(array $recipe, int $version): int
    {
        $rows = $this->scores->getUnscoredLexItems(self::BATCH_LIMIT);
        $done = 0;
        foreach ($rows as $row) {
            $sourceType = 'lex_' . (string)($row['source'] ?? 'eu');
            $result = RecipeScorer::score(
                $recipe,
                (string)($row['title'] ?? ''),
                (string)($row['document_type'] ?? ''),
                $sourceType,
            );
            if ($result === null) {
                continue;
            }
            if ($this->writeScore('lex_item', (int)$row['id'], $result, $version)) {
                $done++;
            }
        }
        return $done;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function rescoreEmails(array $recipe, int $version): int
    {
        $rows = $this->scores->getUnscoredEmails(self::BATCH_LIMIT);
        $done = 0;
        foreach ($rows as $row) {
            $body = (string)($row['text_body'] ?? '');
            if ($body === '') {
                $body = strip_tags((string)($row['html_body'] ?? ''));
            }
            $result = RecipeScorer::score($recipe, (string)($row['subject'] ?? ''), $body, 'email');
            if ($result === null) {
                continue;
            }
            if ($this->writeScore('email', (int)$row['id'], $result, $version)) {
                $done++;
            }
        }
        return $done;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function rescoreCalendarEvents(array $recipe, int $version): int
    {
        $rows = $this->scores->getUnscoredCalendarEvents(self::BATCH_LIMIT);
        $done = 0;
        foreach ($rows as $row) {
            $body = (string)(($row['content'] !== null && $row['content'] !== '')
                ? $row['content']
                : ($row['description'] ?? ''));
            $sourceType = 'calendar_' . (string)($row['source'] ?? 'unknown');
            $result = RecipeScorer::score($recipe, (string)($row['title'] ?? ''), $body, $sourceType);
            if ($result === null) {
                continue;
            }
            if ($this->writeScore('calendar_event', (int)$row['id'], $result, $version)) {
                $done++;
            }
        }
        return $done;
    }

    /**
     * @param array{relevance_score: float, predicted_label: string, explanation: array<string, mixed>|null} $result
     */
    private function writeScore(string $entryType, int $entryId, array $result, int $version): bool
    {
        try {
            $this->scores->upsertRecipeScore(
                $entryType,
                $entryId,
                (float)$result['relevance_score'],
                (string)$result['predicted_label'],
                $result['explanation'],
                $version,
            );
            return true;
        } catch (PDOException $e) {
            error_log('ScoringService ' . $entryType . ' rescore: ' . $e->getMessage());
            return false;
        }
    }
}
