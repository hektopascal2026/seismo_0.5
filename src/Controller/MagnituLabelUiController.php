<?php
/**
 * In-app Magnitu training labels (Magnitu-mini parity) — session UI + CSRF.
 *
 * Lists unlabeled entries from the same export shape as {@see MagnituController}
 * and persists rows to the local {@see MagnituLabelRepository} (never via
 * Bearer `magnitu_labels` from the browser).
 */

declare(strict_types=1);

namespace Seismo\Controller;

use PDOException;
use Seismo\Http\CsrfToken;
use Seismo\Repository\MagnituExportRepository;
use Seismo\Repository\MagnituLabelRepository;

final class MagnituLabelUiController
{
    private const ALLOWED_LABELS = ['investigation_lead', 'important', 'background', 'noise'];

    /** Per-family fetch cap when building the labeling queue. */
    private const PER_FAMILY = 280;

    /** Max entries shipped to the browser after filtering + shuffle. */
    private const QUEUE_CAP = 320;

    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $pageError  = null;
        $filter     = $this->normaliseFilter($_GET['type'] ?? 'all');
        $queueJson  = '[]';

        try {
            $pdo       = getDbConnection();
            $export    = new MagnituExportRepository($pdo);
            $labelRepo = new MagnituLabelRepository($pdo);
            $labeled   = $labelRepo->listLabeledKeys();
            $raw       = $this->gatherEntries($export, $filter);
            $unlabeled = [];
            foreach ($raw as $e) {
                $k = $e['entry_type'] . ':' . $e['entry_id'];
                if (!isset($labeled[$k])) {
                    $unlabeled[] = $e;
                }
            }
            shuffle($unlabeled);
            if (count($unlabeled) > self::QUEUE_CAP) {
                $unlabeled = array_slice($unlabeled, 0, self::QUEUE_CAP);
            }
            $enc = json_encode($unlabeled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
            $queueJson = $enc !== false ? $enc : '[]';
        } catch (\Throwable $e) {
            error_log('Seismo label UI: ' . $e->getMessage());
            $pageError = 'Could not load entries. Check error_log for details.';
        }

        require_once SEISMO_ROOT . '/views/helpers.php';
        require SEISMO_ROOT . '/views/label.php';
    }

    /**
     * POST — saves one label (FormData / x-www-form-urlencoded + `_csrf`).
     */
    public function save(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required'], JSON_UNESCAPED_UNICODE);

            return;
        }
        if (!CsrfToken::verifyRequest()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid or expired CSRF token. Reload the page.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        $entryType = (string)($_POST['entry_type'] ?? '');
        $entryId   = (int)($_POST['entry_id'] ?? 0);
        $label     = (string)($_POST['label'] ?? '');
        $reasoning = trim((string)($_POST['reasoning'] ?? ''));
        $reasoning = $reasoning === '' ? null : $reasoning;

        if (
            !in_array($entryType, MagnituLabelRepository::LABELED_ENTRY_TYPES, true)
            || $entryId <= 0
            || !in_array($label, self::ALLOWED_LABELS, true)
        ) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid entry or label.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        try {
            $repo = new MagnituLabelRepository(getDbConnection());
            $repo->upsert($entryType, $entryId, $label, $reasoning, gmdate('Y-m-d H:i:s'));
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Database error.'], JSON_UNESCAPED_UNICODE);

            return;
        }

        echo json_encode([
            'ok'   => true,
            'csrf' => CsrfToken::ensure(),
        ], JSON_UNESCAPED_UNICODE);
    }

    private function normaliseFilter(mixed $raw): string
    {
        $t = is_string($raw) ? trim($raw) : '';
        if (in_array($t, ['all', 'lex_item', 'feed_item'], true)) {
            return $t;
        }

        return 'all';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gatherEntries(MagnituExportRepository $export, string $filter): array
    {
        $entries = [];
        $lim     = self::PER_FAMILY;
        $one     = 500;

        if ($filter === 'all' || $filter === 'feed_item') {
            $cap = $filter === 'all' ? $lim : $one;
            foreach ($export->listFeedItemsSince(null, $cap) as $row) {
                $entries[] = MagnituController::shapeFeedItem($row);
            }
        }
        if ($filter === 'all' || $filter === 'lex_item') {
            $cap = $filter === 'all' ? $lim : $one;
            foreach ($export->listLexItemsSince(null, $cap) as $row) {
                $entries[] = MagnituController::shapeLexItem($row);
            }
        }
        if ($filter === 'all') {
            foreach ($export->listEmailsSince(null, $lim) as $row) {
                $entries[] = MagnituController::shapeEmail($row);
            }
            foreach ($export->listCalendarEventsSince(null, $lim) as $row) {
                $entries[] = MagnituController::shapeCalendarEvent($row);
            }
        }

        return $entries;
    }
}
