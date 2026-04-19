<?php

declare(strict_types=1);

namespace Seismo\Service;

/**
 * Third-party source adapter contract (plugins). No SQL — persistence is the runner's job.
 *
 * @see \Seismo\Service\PluginRunner
 */
interface SourceFetcherInterface
{
    /** Stable machine id (logs, diagnostics). */
    public function getIdentifier(): string;

    /** Human-readable label for UI and logs. */
    public function getLabel(): string;

    /** Family table / Magnitu entry_type: lex_item, calendar_event, … */
    public function getEntryType(): string;

    /** Key inside the family JSON config (e.g. lex_config.json → "ch"). */
    public function getConfigKey(): string;

    /**
     * Fetch items from the external source and return normalised row arrays.
     * MUST NOT write to the DB. MAY throw — the runner catches and logs.
     * Implementations MUST drop unusable rows (e.g. empty title, missing stable id)
     * so the repository never persists dead dashboard cards.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(array $config): array;
}
