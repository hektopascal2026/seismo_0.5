<?php
/**
 * Star / bookmark state for timeline entries.
 *
 * Backed by the local `entry_favourites` table only — never pass through
 * entryTable(); satellites keep their own favourites on the local DB.
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

final class EntryFavouriteRepository
{
    private const ALLOWED_TYPES = ['feed_item', 'email', 'lex_item', 'calendar_event'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Flip favourite state. Returns the new `is_favourite` value (true = now starred).
     */
    public function toggle(string $entryType, int $entryId): bool
    {
        if (!in_array($entryType, self::ALLOWED_TYPES, true) || $entryId <= 0) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM entry_favourites
                 WHERE entry_type = ? AND entry_id = ? LIMIT 1'
            );
            $stmt->execute([$entryType, $entryId]);
            $exists = (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return false;
        }

        if ($exists) {
            $del = $this->pdo->prepare(
                'DELETE FROM entry_favourites WHERE entry_type = ? AND entry_id = ?'
            );
            $del->execute([$entryType, $entryId]);
            return false;
        }

        $ins = $this->pdo->prepare(
            'INSERT IGNORE INTO entry_favourites (entry_type, entry_id) VALUES (?, ?)'
        );
        $ins->execute([$entryType, $entryId]);
        return true;
    }
}
