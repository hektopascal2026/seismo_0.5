<?php

declare(strict_types=1);

namespace Seismo\Service;

use Seismo\Config\LexConfigStore;
use Seismo\Repository\LexItemRepository;

/**
 * Thin orchestration: config → plugin fetch → repository upsert. Logs failures.
 *
 * Slice 2 ships Fedlex-only entry points (`runFedlex()`). Slice 3 generalises this
 * into `RefreshAllService::runPlugin(string $id)` (or similar); do not add
 * `runEu()`, `runDe()`, etc. here — extend the registry + a single runner method instead.
 */
final class PluginRunner
{
    public function __construct(
        private PluginRegistry $registry,
        private LexItemRepository $lexItems,
        private LexConfigStore $lexConfig,
    ) {
    }

    /**
     * Fedlex (Swiss) only — Slice 2. Other Lex sources stay on 0.4 until later slices.
     */
    public function runFedlex(): PluginRunResult
    {
        $plugin = $this->registry->get('fedlex');
        if ($plugin === null) {
            return PluginRunResult::error('Fedlex plugin not registered.');
        }

        if (isSatellite()) {
            return PluginRunResult::skipped('Satellite mode reads legislation from the mothership; fetch is disabled here.');
        }

        $full = $this->lexConfig->load();
        $block = $full[$plugin->getConfigKey()] ?? [];
        if (empty($block['enabled'])) {
            return PluginRunResult::skipped('Swiss Fedlex is disabled in lex_config.json.');
        }

        try {
            $rows = $plugin->fetch($block);
            $n = $this->lexItems->upsertBatch($rows);

            return PluginRunResult::ok($n);
        } catch (\Throwable $e) {
            error_log('Seismo plugin ' . $plugin->getIdentifier() . ': ' . $e->getMessage());

            return PluginRunResult::error($e->getMessage());
        }
    }
}
