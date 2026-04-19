<?php
/**
 * SQL-only repository for the local `magnitu_labels` table.
 *
 * User-applied Magnitu labels are training data — never deleted by retention
 * policy (see {@see \Seismo\Service\RetentionService} in Slice 5a) and never
 * wrapped in {@see entryTable()} (each instance keeps its own labels even
 * when running as a satellite).
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

final class MagnituLabelRepository
{
    /** Entry types Magnitu may label. `calendar_event` is deliberately excluded. */
    public const LABELED_ENTRY_TYPES = ['feed_item', 'email', 'lex_item'];

    /** Safety cap on GET responses so a runaway client can't OOM the host. */
    public const MAX_LIST_LIMIT = 5000;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Upsert one label. Returns 'inserted' or 'updated'.
     *
     * `$labeledAt` accepts either ISO-8601 (`2026-04-19T10:00:00Z`) or MySQL
     * datetime (`2026-04-19 10:00:00`); it is normalised to the latter.
     */
    public function upsert(
        string $entryType,
        int $entryId,
        string $label,
        ?string $reasoning,
        string $labeledAt,
    ): string {
        $labeledAt = $this->normaliseTimestamp($labeledAt);

        $stmt = $this->pdo->prepare(
            'INSERT INTO magnitu_labels
                 (entry_type, entry_id, label, reasoning, labeled_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 label      = VALUES(label),
                 reasoning  = VALUES(reasoning),
                 labeled_at = VALUES(labeled_at)'
        );
        $stmt->execute([$entryType, $entryId, $label, $reasoning, $labeledAt]);

        return $stmt->rowCount() === 1 ? 'inserted' : 'updated';
    }

    /**
     * List every label, newest first. Bounded by {@see self::MAX_LIST_LIMIT}.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT entry_type, entry_id, label, reasoning, labeled_at
                   FROM magnitu_labels
                  ORDER BY labeled_at DESC
                  LIMIT ' . self::MAX_LIST_LIMIT
            );
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Collapse `T` / `Z` / fractional seconds into `Y-m-d H:i:s`.
     * Used because Magnitu clients historically send either shape.
     */
    private function normaliseTimestamp(string $value): string
    {
        $v = preg_replace('/T/', ' ', $value) ?? $value;
        $v = preg_replace('/\.\d+Z?$|Z$/', '', $v) ?? $v;
        return trim($v);
    }
}
