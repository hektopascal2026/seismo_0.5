<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Config\LexConfigStore;
use Seismo\Plugin\LexFedlex\LexFedlexPlugin;
use Seismo\Repository\LexItemRepository;
use Seismo\Service\PluginRegistry;
use Seismo\Service\PluginRunner;

final class LexController
{
    private const LIST_LIMIT = 50;

    public function show(): void
    {
        $lexItems = [];
        $lexCfg = [];
        $enabledLexSources = [];
        $activeSources = [];
        $lastBySource = [];
        $pageError = null;

        try {
            $pdo = getDbConnection();
            $lexCfg = (new LexConfigStore())->load();
            $enabledLexSources = array_values(array_filter(
                LexItemRepository::LEX_PAGE_SOURCES,
                static function (string $s) use ($lexCfg): bool {
                    return !empty($lexCfg[$s]['enabled']);
                }
            ));

            $sourcesSubmitted = isset($_GET['sources_submitted']);
            if ($sourcesSubmitted) {
                $activeSources = isset($_GET['sources']) ? (array)$_GET['sources'] : [];
            } else {
                $activeSources = $enabledLexSources;
            }
            $activeSources = array_values(array_intersect($activeSources, $enabledLexSources));

            $repo = new LexItemRepository($pdo);
            if ($activeSources !== []) {
                $lexItems = $repo->listBySources($activeSources, self::LIST_LIMIT, 0);
            }

            $lastBySource = $repo->getLastFetchedBySources(LexItemRepository::LEX_PAGE_SOURCES);
        } catch (\Throwable $e) {
            error_log('Seismo lex: ' . $e->getMessage());
            $pageError = 'Could not load legislation list. Check error_log for details.';
        }

        $lastFetchedBySource = $lastBySource;

        $basePath = getBasePath();
        $satellite = isSatellite();
        $chCfg = is_array($lexCfg['ch'] ?? null) ? $lexCfg['ch'] : [];

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/lex.php';
    }

    public function refreshFedlex(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLex();

            return;
        }

        $pdo = getDbConnection();
        $runner = new PluginRunner(
            new PluginRegistry(),
            new LexItemRepository($pdo),
            new LexConfigStore()
        );
        $result = $runner->runFedlex();

        if ($result->isOk()) {
            $_SESSION['success'] = 'Fedlex refresh finished: ' . $result->count . ' row(s) processed.';
        } elseif ($result->status === 'skipped') {
            $_SESSION['error'] = $result->message ?? 'Fedlex refresh skipped.';
        } else {
            $_SESSION['error'] = 'Fedlex refresh failed: ' . ($result->message ?? 'unknown error');
        }

        $this->redirectToLex();
    }

    public function saveLexCh(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToLex();

            return;
        }

        $store = new LexConfigStore();
        $isEnabled = static function (string $field, bool $default = false): bool {
            if (!array_key_exists($field, $_POST)) {
                return $default;
            }
            $raw = $_POST[$field];
            if (is_array($raw)) {
                return $raw !== [];
            }
            $value = strtolower(trim((string)$raw));

            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        };

        try {
            $full = $store->load();
            $ch = is_array($full['ch'] ?? null) ? $full['ch'] : [];
            $ch['enabled'] = $isEnabled('ch_enabled', (bool)($ch['enabled'] ?? true));
            $ch['language'] = LexFedlexPlugin::normalizeFedlexLanguage(
                (string)($_POST['ch_language'] ?? $ch['language'] ?? 'DEU')
            );
            $ch['lookback_days'] = max(1, (int)($_POST['ch_lookback_days'] ?? $ch['lookback_days'] ?? 90));
            $ch['limit'] = max(1, (int)($_POST['ch_limit'] ?? $ch['limit'] ?? 100));
            $ch['notes'] = trim((string)($_POST['ch_notes'] ?? $ch['notes'] ?? ''));

            $rtRaw = trim((string)($_POST['ch_resource_types'] ?? ''));
            if ($rtRaw !== '') {
                $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $rtRaw)));
                $existingTypes = [];
                foreach (($ch['resource_types'] ?? []) as $rt) {
                    if (is_array($rt) && isset($rt['id'])) {
                        $existingTypes[(int)$rt['id']] = $rt['label'] ?? '';
                    }
                }
                $newTypes = [];
                foreach ($ids as $id) {
                    $newTypes[] = ['id' => $id, 'label' => $existingTypes[$id] ?? 'Type ' . $id];
                }
                $ch['resource_types'] = $newTypes;
            }

            $store->saveChBlock($ch);
            $_SESSION['success'] = 'Swiss Fedlex settings saved.';
        } catch (\Throwable $e) {
            error_log('Seismo save_lex_ch: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not save Fedlex settings.';
        }

        $this->redirectToLex();
    }

    private function redirectToLex(): void
    {
        $base = getBasePath();
        header('Location: ' . $base . '/index.php?action=lex', true, 303);
        exit;
    }
}
