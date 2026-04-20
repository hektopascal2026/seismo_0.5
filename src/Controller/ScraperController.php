<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\ScraperConfigRepository;
use Seismo\Repository\SystemConfigRepository;

final class ScraperController
{
    private const LIST_LIMIT = 50;

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $satellite = isSatellite();

        $view = (isset($_GET['view']) && (string)$_GET['view'] === 'sources') ? 'sources' : 'items';
        $editId = (int)($_GET['edit'] ?? 0);

        $allItems    = [];
        $configsList = [];
        $editRow     = null;
        $pageError   = null;
        $alertThreshold = 0.75;

        try {
            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $raw    = $config->get('alert_threshold');
            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                $alertThreshold = max(0.0, min(1.0, (float)$raw));
            }

            $entryRepo = new EntryRepository($pdo);
            $allItems = $entryRepo->getScraperModuleTimeline(self::LIST_LIMIT, 0);

            $scRepo = new ScraperConfigRepository($pdo);
            $configsList = $scRepo->listAll(ScraperConfigRepository::MAX_LIMIT, 0);
            if ($editId > 0) {
                $editRow = $scRepo->findById($editId);
            }
        } catch (\Throwable $e) {
            error_log('Seismo scraper: ' . $e->getMessage());
            $pageError = 'Could not load scraper page. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';

        $showDaySeparators = true;
        $showFavourites    = true;
        $searchQuery       = '';
        $returnQuery       = $this->buildReturnQuery();
        $currentView       = 'newest';
        $emptyTimelineHint = 'default';
        $timelineFilter    = \Seismo\Repository\TimelineFilter::fromQueryArray([]);
        $filterPillOptions = ['feed_categories' => [], 'lex_sources' => [], 'email_tags' => []];
        $dashboardError    = $pageError;

        require SEISMO_ROOT . '/views/scraper.php';
    }

    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — scraper configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $repo = new ScraperConfigRepository(getDbConnection());
            $payload = [
                'name'           => (string)($_POST['name'] ?? ''),
                'url'            => (string)($_POST['url'] ?? ''),
                'link_pattern'   => (string)($_POST['link_pattern'] ?? ''),
                'date_selector'  => (string)($_POST['date_selector'] ?? ''),
                'category'       => (string)($_POST['category'] ?? 'scraper'),
                'disabled'       => ((string)($_POST['disabled'] ?? '0')) === '1',
            ];
            if ($id > 0) {
                $repo->update($id, $payload);
                $_SESSION['success'] = 'Scraper source updated.';
            } else {
                $newId = $repo->insert($payload);
                $_SESSION['success'] = 'Scraper source added (#' . $newId . ').';
            }
        } catch (\Throwable $e) {
            error_log('Seismo scraper_save: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    public function delete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'sources']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — scraper configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid scraper id.';

            $this->redirect(['view' => 'sources']);

            return;
        }

        try {
            $repo = new ScraperConfigRepository(getDbConnection());
            $repo->delete($id);
            $_SESSION['success'] = 'Scraper source deleted.';
        } catch (\Throwable $e) {
            error_log('Seismo scraper_delete: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function redirect(array $query): void
    {
        $q = array_merge(['action' => 'scraper'], $query);
        header('Location: ?' . http_build_query($q), true, 303);
        exit;
    }

    private function buildReturnQuery(): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = 'scraper';

        return http_build_query($p);
    }
}
