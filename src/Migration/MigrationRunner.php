<?php
/**
 * Ordered, versioned migrations. Each migration bumps `magnitu_config.schema_version`
 * (until Slice 5a renames the table to `system_config`).
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use RuntimeException;
use Seismo\Repository\MagnituConfigRepository;

final class MigrationRunner
{
    /** Highest schema version shipped by built-in migrations. */
    public const LATEST_VERSION = Migration004ExportKey::VERSION;

    private MagnituConfigRepository $magnituConfig;

    public function __construct(
        private PDO $pdo,
        ?MagnituConfigRepository $magnituConfig = null,
    ) {
        $this->magnituConfig = $magnituConfig ?? new MagnituConfigRepository($pdo);
    }

    /**
     * Returns current stored schema version, or 0 if unreadable / not set.
     * Delegates to MagnituConfigRepository (same semantics when the table is missing).
     */
    public function getCurrentVersion(): int
    {
        $v = $this->magnituConfig->getSchemaVersion();
        return $v ?? 0;
    }

    /**
     * Apply all pending migrations in order. Idempotent on re-run when already up to date.
     *
     * @param callable(string): void $log Echo or fwrite for progress lines
     */
    public function run(callable $log): void
    {
        if (isSatellite()) {
            throw new RuntimeException(
                'Migrations only run on the mothership. This instance has SEISMO_MOTHERSHIP_DB set (satellite mode); do not apply DDL to the local database.'
            );
        }

        $current = $this->getCurrentVersion();

        $migrations = [
            Migration001BaseSchema::VERSION   => new Migration001BaseSchema(),
            Migration002PluginRunLog::VERSION  => new Migration002PluginRunLog(),
            Migration003EmailsUnified::VERSION => new Migration003EmailsUnified(),
            Migration004ExportKey::VERSION     => new Migration004ExportKey(),
        ];

        ksort($migrations, SORT_NUMERIC);

        foreach ($migrations as $targetVersion => $migration) {
            if ($current >= $targetVersion) {
                continue;
            }
            $log("Applying migration to version {$targetVersion} …\n");
            $migration->apply($this->pdo);
            $this->magnituConfig->set('schema_version', (string)$targetVersion);
            $current = $targetVersion;
            $log("OK — schema version is now {$targetVersion}.\n");
        }

        if ($current >= self::LATEST_VERSION) {
            $log('Schema is up to date (' . self::LATEST_VERSION . ").\n");
        }
    }
}
