<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Outcome of a single plugin or core fetcher run. Persisted to `plugin_run_log` by
 * RefreshAllService / CoreRunner unless {@see self::$persistToPluginRunLog} is
 * false (throttle skips — stdout / cron mail only).
 */
final class PluginRunResult
{
    public function __construct(
        public readonly string $status,
        public readonly int $count = 0,
        public readonly ?string $message = null,
        public readonly bool $persistToPluginRunLog = true,
    ) {
    }

    public static function ok(int $count): self
    {
        return new self('ok', $count);
    }

    public static function skipped(string $message): self
    {
        return new self('skipped', 0, $message, true);
    }

    /**
     * Skipped because the per-source throttle window has not elapsed. Must not
     * write a `plugin_run_log` row (avoids cron noise).
     */
    public static function throttleSkipped(string $message): self
    {
        return new self('skipped', 0, $message, false);
    }

    public static function error(string $message): self
    {
        return new self('error', 0, $message);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
