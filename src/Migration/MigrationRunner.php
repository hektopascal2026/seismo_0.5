<?php
/**
 * Ordered, versioned migrations. Each migration bumps the
 * `schema_version` row in `system_config` (renamed from `magnitu_config`
 * by Migration 005 / Slice 5a). {@see SystemConfigRepository::set()}
 * transparently falls back to the legacy table name in the brief window
 * between "Slice 5a code uploaded" and "Migration 005 applied", so the
 * runner can read the current version to decide whether 005 is pending.
 */

declare(strict_types=1);

namespace Seismo\Migration;

use PDO;
use RuntimeException;
use Seismo\Repository\SystemConfigRepository;

final class MigrationRunner
{
    /** Highest schema version shipped by built-in migrations. */
    public const LATEST_VERSION = Migration005SystemConfig::VERSION;

    private SystemConfigRepository $systemConfig;

    public function __construct(
        private PDO $pdo,
        ?SystemConfigRepository $systemConfig = null,
    ) {
        $this->systemConfig = $systemConfig ?? new SystemConfigRepository($pdo);
    }

    /**
     * Returns current stored schema version, or 0 if unreadable / not set.
     * Delegates to {@see SystemConfigRepository} (same semantics when the
     * table is missing, including the legacy-name fallback during the
     * Slice 5a transition).
     */
    public function getCurrentVersion(): int
    {
        $v = $this->systemConfig->getSchemaVersion();
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
            Migration001BaseSchema::VERSION    => new Migration001BaseSchema(),
            Migration002PluginRunLog::VERSION  => new Migration002PluginRunLog(),
            Migration003EmailsUnified::VERSION => new Migration003EmailsUnified(),
            Migration004ExportKey::VERSION     => new Migration004ExportKey(),
            Migration005SystemConfig::VERSION  => new Migration005SystemConfig(),
        ];

        ksort($migrations, SORT_NUMERIC);

        foreach ($migrations as $targetVersion => $migration) {
            if ($current >= $targetVersion) {
                continue;
            }
            $log("Applying migration to version {$targetVersion} …\n");
            $migration->apply($this->pdo);
            $this->systemConfig->set('schema_version', (string)$targetVersion);
            $current = $targetVersion;
            $log("OK — schema version is now {$targetVersion}.\n");
        }

        if ($current >= self::LATEST_VERSION) {
            $log('Schema is up to date (' . self::LATEST_VERSION . ").\n");
        }
    }
}
