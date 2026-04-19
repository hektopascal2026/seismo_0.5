<?php

declare(strict_types=1);

namespace Seismo\Config;

/**
 * Reads/writes `calendar_config.json` beside bootstrap (same contract as 0.4 getCalendarConfig).
 */
final class CalendarConfigStore
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (SEISMO_ROOT . '/calendar_config.json');
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
     * @param array<string, mixed> $config
     */
    public function save(array $config): void
    {
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write calendar_config.json.');
        }
    }

    /**
     * Replace only the `parliament_ch` block, keeping all other keys from load().
     *
     * @param array<string, mixed> $block
     */
    public function saveParlChBlock(array $block): void
    {
        $full = $this->load();
        $full['parliament_ch'] = array_replace_recursive(
            is_array($full['parliament_ch'] ?? null) ? $full['parliament_ch'] : [],
            $block
        );
        $this->save($full);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultConfig(): array
    {
        return [
            'parliament_ch' => [
                'enabled'          => true,
                'api_base'         => 'https://ws.parlament.ch/odata.svc',
                'language'         => 'DE',
                'lookforward_days' => 90,
                'lookback_days'    => 7,
                'limit'            => 100,
                'business_types'   => [
                    1  => 'Geschaeft des Bundesrates',
                    3  => 'Standesinitiative',
                    4  => 'Parlamentarische Initiative',
                    5  => 'Motion',
                    6  => 'Postulat',
                    8  => 'Interpellation',
                    12 => 'Einfache Anfrage',
                ],
                'notes'            => '',
            ],
        ];
    }
}
