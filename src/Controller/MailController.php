<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;
use Seismo\Repository\EmailSubscriptionRepository;
use Seismo\Repository\EntryRepository;
use Seismo\Repository\SystemConfigRepository;

final class MailController
{
    private const LIST_LIMIT = 50;

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $satellite = isSatellite();

        $view = (isset($_GET['view']) && (string)$_GET['view'] === 'subscriptions') ? 'subscriptions' : 'items';
        $editId = (int)($_GET['edit'] ?? 0);
        $subscriptionId = (int)($_GET['subscription'] ?? 0);

        $allItems             = [];
        $subscriptions        = [];
        $subscriptionLatest   = [];
        $subscriptionFilter   = null;
        $editRow              = null;
        $pageError            = null;
        $alertThreshold       = 0.75;

        try {
            $pdo = getDbConnection();
            $config = new SystemConfigRepository($pdo);
            $raw    = $config->get('alert_threshold');
            if ($raw !== null && $raw !== '' && is_numeric($raw)) {
                $alertThreshold = max(0.0, min(1.0, (float)$raw));
            }

            $entryRepo = new EntryRepository($pdo);
            $subRepo   = new EmailSubscriptionRepository($pdo);

            if ($view === 'items' && $subscriptionId > 0) {
                $subForFilter = $subRepo->findById($subscriptionId);
                if ($subForFilter !== null) {
                    $subscriptionFilter = $subForFilter;
                    $allItems = $entryRepo->getEmailModuleTimelineForSubscription(
                        (string)$subForFilter['match_type'],
                        (string)$subForFilter['match_value'],
                        self::LIST_LIMIT,
                        0
                    );
                } else {
                    $allItems = $entryRepo->getEmailModuleTimeline(self::LIST_LIMIT, 0);
                }
            } else {
                $allItems = $entryRepo->getEmailModuleTimeline(self::LIST_LIMIT, 0);
            }

            $subscriptions = $subRepo->listAll(EmailSubscriptionRepository::MAX_LIMIT, 0);
            if ($view === 'subscriptions') {
                foreach ($subscriptions as $row) {
                    $sid = (int)$row['id'];
                    $subscriptionLatest[$sid] = $entryRepo->peekLatestEmailForSubscription(
                        (string)$row['match_type'],
                        (string)$row['match_value']
                    );
                }
            }
            if ($editId > 0) {
                $editRow = $subRepo->findById($editId);
            }
        } catch (\Throwable $e) {
            error_log('Seismo mail: ' . $e->getMessage());
            $pageError = 'Could not load mail page. Check error_log for details.';
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

        require SEISMO_ROOT . '/views/mail.php';
    }

    public function saveSubscription(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'subscriptions']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'subscriptions']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — email subscriptions are managed on the mothership only.';
            $this->redirect(['view' => 'subscriptions']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        try {
            $repo = new EmailSubscriptionRepository(getDbConnection());
            $payload = [
                'match_type'             => (string)($_POST['match_type'] ?? 'domain'),
                'match_value'            => (string)($_POST['match_value'] ?? ''),
                'display_name'           => (string)($_POST['display_name'] ?? ''),
                'category'               => (string)($_POST['category'] ?? ''),
                'disabled'               => ((string)($_POST['disabled'] ?? '0')) === '1',
                'show_in_magnitu'        => ((string)($_POST['show_in_magnitu'] ?? '0')) === '1',
                'strip_listing_boilerplate' => ((string)($_POST['strip_listing_boilerplate'] ?? '0')) === '1',
                'unsubscribe_url'        => (string)($_POST['unsubscribe_url'] ?? ''),
                'unsubscribe_mailto'     => (string)($_POST['unsubscribe_mailto'] ?? ''),
                'unsubscribe_one_click'  => ((string)($_POST['unsubscribe_one_click'] ?? '0')) === '1',
            ];
            if ($id > 0) {
                $repo->update($id, $payload);
                $_SESSION['success'] = 'Subscription updated.';
            } else {
                $newId = $repo->insert($payload);
                $_SESSION['success'] = 'Subscription added (#' . $newId . ').';
            }
        } catch (\Throwable $e) {
            error_log('Seismo mail_subscription_save: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'subscriptions']);
    }

    public function deleteSubscription(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'subscriptions']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'subscriptions']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — email subscriptions are managed on the mothership only.';
            $this->redirect(['view' => 'subscriptions']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid subscription.';

            $this->redirect(['view' => 'subscriptions']);

            return;
        }

        try {
            $repo = new EmailSubscriptionRepository(getDbConnection());
            $repo->softDelete($id);
            $_SESSION['success'] = 'Subscription removed.';
        } catch (\Throwable $e) {
            error_log('Seismo mail_subscription_delete: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'subscriptions']);
    }

    /**
     * Disable subscription (one-click unsubscribe style).
     */
    public function disableSubscription(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirect(['view' => 'subscriptions']);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            $this->redirect(['view' => 'subscriptions']);

            return;
        }
        if (isSatellite()) {
            $_SESSION['error'] = 'Satellite mode — email subscriptions are managed on the mothership only.';
            $this->redirect(['view' => 'subscriptions']);

            return;
        }

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = 'Invalid subscription.';

            $this->redirect(['view' => 'subscriptions']);

            return;
        }

        try {
            $repo = new EmailSubscriptionRepository(getDbConnection());
            $repo->setDisabled($id, true);
            $_SESSION['success'] = 'Subscription disabled.';
        } catch (\Throwable $e) {
            error_log('Seismo mail_subscription_disable: ' . $e->getMessage());
            $_SESSION['error'] = $e->getMessage();
        }

        $this->redirect(['view' => 'subscriptions']);
    }

    /**
     * @param array<string, scalar|null> $query
     */
    private function redirect(array $query): void
    {
        $q = array_merge(['action' => 'mail'], $query);
        header('Location: ?' . http_build_query($q), true, 303);
        exit;
    }

    private function buildReturnQuery(): string
    {
        $p = $_GET;
        if (!is_array($p)) {
            $p = [];
        }
        $p['action'] = 'mail';

        return http_build_query($p);
    }
}
