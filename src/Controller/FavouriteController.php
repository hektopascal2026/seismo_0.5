<?php
/**
 * POST-only favourite (star) toggle for dashboard entries.
 *
 * Slice 1.5 — mirrors 0.4's handleToggleFavourite: validates input, toggles
 * the local entry_favourites row, redirects back preserving query string.
 */

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Repository\EntryFavouriteRepository;

final class FavouriteController
{
    private const ALLOWED_TYPES = ['feed_item', 'email', 'lex_item', 'calendar_event'];

    public function toggle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectToIndex([]);
            return;
        }

        $entryType = trim((string)($_POST['entry_type'] ?? ''));
        $entryId   = (int)($_POST['entry_id'] ?? 0);
        $returnRaw = trim((string)($_POST['return_query'] ?? ''));

        if (!in_array($entryType, self::ALLOWED_TYPES, true) || $entryId <= 0) {
            $_SESSION['error'] = 'Invalid favourite request.';
            $this->redirectFromReturnQuery($returnRaw);
            return;
        }

        try {
            $pdo  = getDbConnection();
            $repo = new EntryFavouriteRepository($pdo);
            $repo->toggle($entryType, $entryId);
        } catch (\Throwable $e) {
            error_log('Seismo toggle_favourite: ' . $e->getMessage());
            $_SESSION['error'] = 'Could not update favourite. Try again.';
        }

        $this->redirectFromReturnQuery($returnRaw);
    }

    /**
     * Parse `return_query` (no leading "?") or fall back to index.
     */
    private function redirectFromReturnQuery(string $returnRaw): void
    {
        $params = [];
        if ($returnRaw !== '') {
            parse_str(ltrim($returnRaw, '?'), $params);
        }
        $this->redirectToIndex($params);
    }

    /**
     * @param array<string, scalar|array> $params
     */
    private function redirectToIndex(array $params): void
    {
        $params['action'] = 'index';
        unset($params['entry_type'], $params['entry_id']);
        $qs = http_build_query($params);
        // Relative `?…` keeps subfolder installs working (same as 0.4).
        header('Location: ?' . $qs);
        exit;
    }
}
