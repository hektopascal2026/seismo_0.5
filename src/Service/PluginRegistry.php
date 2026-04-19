<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Plugin\LexFedlex\LexFedlexPlugin;
use Seismo\Plugin\ParlCh\ParlChPlugin;

/**
 * Explicit plugin list — no filesystem scanning (see architecture rules).
 *
 * Order here is the order RefreshAllService::runAll() iterates. Cheap /
 * fast plugins first so a slow upstream doesn't delay everything else.
 */
final class PluginRegistry
{
    /** @var array<string, SourceFetcherInterface> */
    private array $plugins;

    public function __construct()
    {
        $this->plugins = [
            'fedlex'  => new LexFedlexPlugin(),
            'parl_ch' => new ParlChPlugin(),
        ];
    }

    public function get(string $identifier): ?SourceFetcherInterface
    {
        return $this->plugins[$identifier] ?? null;
    }

    public function has(string $identifier): bool
    {
        return isset($this->plugins[$identifier]);
    }

    /**
     * @return array<string, SourceFetcherInterface>
     */
    public function all(): array
    {
        return $this->plugins;
    }
}
