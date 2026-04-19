<?php
/**
 * Reads and writes to the `magnitu_config` key/value table.
 *
 * IMPORTANT: `magnitu_config` is a **local scoring table**, not an entry
 * source. It is never wrapped in entryTable() and never lives on the
 * mothership in satellite mode — each satellite keeps its own Magnitu
 * config (API key, recipe, alert threshold, schema version, etc.).
 */

declare(strict_types=1);

namespace Seismo\Repository;

use PDO;
use PDOException;

final class MagnituConfigRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Schema version the migrator wrote the last time it ran.
     *
     * Returns null when the table doesn't exist yet (brand-new database,
     * `php migrate.php` has never been executed).
     */
    public function getSchemaVersion(): ?int
    {
        $raw = $this->get('schema_version');
        return $raw === null ? null : (int)$raw;
    }

    /**
     * Raw string fetch for any magnitu_config key. Returns null when the
     * table is absent or the key isn't present.
     */
    public function get(string $key): ?string
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT config_value FROM magnitu_config WHERE config_key = ?'
            );
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
        } catch (PDOException $e) {
            // magnitu_config likely doesn't exist yet — treat as "no value".
            return null;
        }
        if ($value === false || $value === null) {
            return null;
        }
        return (string)$value;
    }

    /**
     * Upsert a key. Used by migrations for `schema_version` and by settings
     * code later. Throws PDOException if `magnitu_config` does not exist — only
     * call after the base schema migration has created the table.
     */
    public function set(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO magnitu_config (config_key, config_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        $stmt->execute([$key, $value]);
    }
}
