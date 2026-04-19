<?php

declare(strict_types=1);

namespace Seismo\Config;

/**
 * Reads/writes `lex_config.json` beside bootstrap (same contract as 0.4 getLexConfig).
 */
final class LexConfigStore
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (SEISMO_ROOT . '/lex_config.json');
    }

    /**
     * Full config with defaults merged for any missing top-level keys.
     *
     * @return array<string, mixed>
     */
    public function load(): array
    {
        $defaults = $this->defaultConfig();
        if (!is_file($this->path)) {
            return $defaults;
        }
        $raw = @file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return $defaults;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $defaults;
        }

        /** @var array<string, mixed> */
        return array_replace_recursive($defaults, $data);
    }

    /**
     * Persist entire config (pretty-printed JSON).
     *
     * @param array<string, mixed> $config
     */
    public function save(array $config): void
    {
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write lex_config.json.');
        }
    }

    /**
     * Replace only the `ch` block, keeping all other keys from load().
     *
     * @param array<string, mixed> $chBlock
     */
    public function saveChBlock(array $chBlock): void
    {
        $full = $this->load();
        $full['ch'] = array_replace_recursive($full['ch'] ?? [], $chBlock);
        $this->save($full);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array
    {
        return [
            'eu' => [
                'enabled' => true,
                'endpoint' => 'https://publications.europa.eu/webapi/rdf/sparql',
                'language' => 'ENG',
                'lookback_days' => 90,
                'limit' => 100,
                'document_class' => 'cdm:legislation_secondary',
                'notes' => '',
            ],
            'ch' => [
                'enabled' => true,
                'endpoint' => 'https://fedlex.data.admin.ch/sparqlendpoint',
                'language' => 'DEU',
                'lookback_days' => 90,
                'limit' => 100,
                'resource_types' => [
                    ['id' => 21, 'label' => 'Bundesgesetz'],
                    ['id' => 22, 'label' => 'Dringliches Bundesgesetz'],
                    ['id' => 29, 'label' => 'Verordnung des Bundesrates'],
                    ['id' => 26, 'label' => 'Departementsverordnung'],
                    ['id' => 27, 'label' => 'Amtsverordnung'],
                    ['id' => 28, 'label' => 'Verordnung der Bundesversammlung'],
                    ['id' => 8,  'label' => 'Einfacher Bundesbeschluss (andere)'],
                    ['id' => 9,  'label' => 'Bundesbeschluss (fakultatives Referendum)'],
                    ['id' => 10, 'label' => 'Bundesbeschluss (obligatorisches Referendum)'],
                    ['id' => 31, 'label' => 'Internationaler Rechtstext bilateral'],
                    ['id' => 32, 'label' => 'Internationaler Rechtstext multilateral'],
                ],
                'notes' => '',
            ],
            'de' => [
                'enabled' => true,
                'feed_url' => 'https://www.recht.bund.de/rss/feeds/rss_bgbl-1-2.xml?nn=211452',
                'lookback_days' => 90,
                'limit' => 100,
                'notes' => '',
            ],
            'ch_bger' => [
                'enabled' => true,
                'base_url' => 'https://entscheidsuche.ch',
                'lookback_days' => 90,
                'limit' => 100,
                'notes' => '',
            ],
            'ch_bge' => [
                'enabled' => false,
                'base_url' => 'https://entscheidsuche.ch',
                'lookback_days' => 90,
                'limit' => 50,
                'notes' => '',
            ],
            'ch_bvger' => [
                'enabled' => true,
                'base_url' => 'https://entscheidsuche.ch',
                'lookback_days' => 90,
                'limit' => 100,
                'notes' => '',
            ],
            'parl_mm' => [
                'enabled' => false,
                'api_base' => "https://www.parlament.ch/press-releases/_api/web/lists/getByTitle('Pages')/items",
                'language' => 'de',
                'lookback_days' => 90,
                'limit' => 50,
                'notes' => '',
            ],
            'fr' => [
                'enabled' => false,
                'client_id' => '',
                'client_secret' => '',
                'fond' => 'JORF',
                'natures' => ['LOI', 'ORDONNANCE', 'DECRET'],
                'lookback_days' => 90,
                'limit' => 100,
                'notes' => '',
            ],
            'jus_banned_words' => [],
        ];
    }
}
