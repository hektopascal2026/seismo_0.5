<?php

declare(strict_types=1);

namespace Seismo\Controller;

use Seismo\Http\CsrfToken;

/**
 * In-app product overview (Slice 9). User-facing copy; no SQL.
 */
final class AboutController
{
    public function show(): void
    {
        $csrfField = CsrfToken::field();
        $basePath  = getBasePath();
        $accent    = seismoBrandAccent();

        require_once SEISMO_ROOT . '/views/helpers.php';

        $headerTitle    = seismoBrandTitle();
        $headerSubtitle = !isSatellite() ? 'What this app does' : null;
        $activeNav      = 'about';

        require SEISMO_ROOT . '/views/about.php';
    }
}
