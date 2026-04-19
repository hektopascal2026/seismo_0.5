<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Outcome of a single plugin run (until plugin_run_log lands in Slice 3).
 */
final class PluginRunResult
{
    public function __construct(
        public readonly string $status,
        public readonly int $count = 0,
        public readonly ?string $message = null,
    ) {
    }

    public static function ok(int $count): self
    {
        return new self('ok', $count);
    }

    public static function skipped(string $message): self
    {
        return new self('skipped', 0, $message);
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
