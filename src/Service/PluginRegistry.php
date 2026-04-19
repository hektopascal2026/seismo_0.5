<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Plugin\LexFedlex\LexFedlexPlugin;

/**
 * Explicit plugin list — no filesystem scanning (see architecture rules).
 */
final class PluginRegistry
{
    /** @var array<string, SourceFetcherInterface> */
    private array $plugins;

    public function __construct()
    {
        $this->plugins = [
            'fedlex' => new LexFedlexPlugin(),
        ];
    }

    public function get(string $identifier): ?SourceFetcherInterface
    {
        return $this->plugins[$identifier] ?? null;
    }

    /**
     * @return array<string, SourceFetcherInterface>
     */
    public function all(): array
    {
        return $this->plugins;
    }
}
