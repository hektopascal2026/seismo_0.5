<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\FeedRepository;
use Seismo\Repository\SystemConfigRepository;

final class FeedController
{
    private const LIST_LIMIT = 50;

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $satellite = isSatellite();

        $view = (isset($_GET['view']) && (string)$_GET['view'] === 'sources') ? 'sources' : 'items';
        $editId = (int)($_GET['edit'] ?? 0);

        $allItems   = [];
        $feedsList  = [];
        $editRow    = null;
        $pageError  = null; // set on catch
        $alertThreshold = 0.75;

        try {
            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $raw    = $config->get('alert_threshold');
            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                $alertThreshold = max(0.0, min(1.0, (float)$raw));
            }

            $entryRepo = new EntryRepository($pdo);
            $allItems = $entryRepo->getRssModuleTimeline(self::LIST_LIMIT, 0);

            $feedRepo = new FeedRepository($pdo);
            $feedsList = $feedRepo->listAll(FeedRepository::MAX_LIMIT, 0);
            if ($editId > 0) {
                $editRow = $feedRepo->findById($editId);
            }
        } catch (\Throwable $e) {
            error_log('Seismo feeds: ' . $e->getMessage());
            $pageError = 'Could not load feeds page. Check error_log for details.';
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

        require SEISMO_ROOT . '/views/feeds.php';
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
            $_SESSION['error'] = 'Satellite mode — feed configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo  = getDbConnection();
            $repo = new FeedRepository($pdo);
            $payload = [
                'url'          => (string)($_POST['url'] ?? ''),
                'title'        => (string)($_POST['title'] ?? ''),
                'source_type'  => (string)($_POST['source_type'] ?? 'rss'),
                'description'  => (string)($_POST['description'] ?? ''),
                'link'         => (string)($_POST['link'] ?? ''),
                'category'     => (string)($_POST['category'] ?? ''),
                'disabled'     => ((string)($_POST['disabled'] ?? '0')) === '1',
            ];
            if ($id > 0) {
                $repo->update($id, $payload);
                $_SESSION['success'] = 'Feed updated.';
            } else {
                $newId = $repo->insert($payload);
                $_SESSION['success'] = 'Feed added (#' . $newId . ').';
            }
        } catch (\Throwable $e) {
            error_log('Seismo feed_save: ' . $e->getMessage());
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
            $_SESSION['error'] = 'Satellite mode — feed configuration is managed on the mothership only.';
            $this->redirect(['view' => 'sources']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid feed.';

            $this->redirect(['view' => 'sources']);

            return;
        }

        try {
            $repo = new FeedRepository(getDbConnection());
            $repo->delete($id);
            $_SESSION['success'] = 'Feed deleted.';
        } catch (\Throwable $e) {
            error_log('Seismo feed_delete: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'sources']);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function redirect(array $query): void
    {
        $q = array_merge(['action' => 'feeds'], $query);
        header('Location: ?' . http_build_query($q), true, 303);
        exit;
    }

    private function buildReturnQuery(): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = 'feeds';

        return http_build_query($p);
    }
}
