<?php
/**
 * Ordered, versioned migrations. Each migration bumps `magnitu_config.schema_version`
 * (until Slice 5a renames the table to `system_config`).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use PDOException;

final class MigrationRunner
{
    /** Highest schema version shipped by built-in migrations. */
    public const LATEST_VERSION = Migration001BaseSchema::VERSION;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Returns current stored schema version, or 0 if unreadable / not set.
     */
    public function getCurrentVersion(): int
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT config_value FROM magnitu_config WHERE config_key = 'schema_version'"
            );
            if ($stmt === false) {
                return 0;
            }
            $v = $stmt->fetchColumn();
            if ($v === false || $v === null || $v === '') {
                return 0;
            }
            return (int)$v;
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Apply all pending migrations in order. Idempotent on re-run when already up to date.
     *
     * @param callable(string): void $log Echo or fwrite for progress lines
     */
    public function run(callable $log): void
    {
        $current = $this->getCurrentVersion();

        $migrations = [
            Migration001BaseSchema::VERSION => new Migration001BaseSchema(),
        ];

        ksort($migrations, SORT_NUMERIC);

        foreach ($migrations as $targetVersion => $migration) {
            if ($current >= $targetVersion) {
                continue;
            }
            $log("Applying migration to version {$targetVersion} …\n");
            $migration->apply($this->pdo);
            $this->setSchemaVersion($targetVersion);
            $current = $targetVersion;
            $log("OK — schema version is now {$targetVersion}.\n");
        }

        if ($current >= self::LATEST_VERSION) {
            $log('Schema is up to date (' . self::LATEST_VERSION . ").\n");
        }
    }

    private function setSchemaVersion(int $version): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO magnitu_config (config_key, config_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        );
        $stmt->execute(['schema_version', (string)$version]);
    }
}
