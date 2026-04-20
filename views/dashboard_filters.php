<?php
/**
 * Dashboard filter editor (checkbox pills, GET submit to Timeline).
 *
 * @var string $csrfField
 * @var ?string $dashboardError
 * @var array{feed_categories: list<string>, lex_sources: list<string>, email_tags: list<string>} $filterPillOptions
 * @var \Seismo\Repository\TimelineFilter $timelineFilter
 */

declare(strict_types=1);

$basePath = getBasePath();
$accent   = seismoBrandAccent();

$headerTitle    = seismoBrandTitle();
$headerSubtitle = !isSatellite() ? 'ein Prototyp von hektopascal.org | v' . SEISMO_VERSION : null;
$activeNav      = 'filter';

$searchQuery = trim((string)($_GET['q'] ?? ''));
$currentView = (isset($_GET['view']) && (string)$_GET['view'] === 'favourites')
    ? 'favourites'
    : 'newest';

$filterNavParams = ['action' => 'filter'];
foreach (['q', 'view', 'limit', 'offset', 'none', 'filter_form', 'filters'] as $k) {
    if (!isset($_GET[$k])) {
        continue;
    }
    $v = $_GET[$k];
    if (is_array($v)) {
        $filterNavParams[$k] = $v;
    } elseif (is_scalar($v)) {
        $filterNavParams[$k] = $v;
    }
}
$filterNavQs = http_build_query($filterNavParams);

$feedOn = static function (string $cat) use ($timelineFilter): bool {
    return !in_array($cat, $timelineFilter->excludedFeedCategories, true);
};
$lexOn = static function (string $src) use ($timelineFilter): bool {
    return !in_array($src, $timelineFilter->excludedLexSources, true);
};
$mailOn = static function (string $tg) use ($timelineFilter): bool {
    return !in_array($tg, $timelineFilter->excludedEmailTags, true);
};
$legOn = !$timelineFilter->excludeCalendar;
$jusOn = !$timelineFilter->excludeJusLex;

$formAction = $basePath . '/index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Filters — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
    <?php if ($accent): ?>
    <style>:root { --seismo-accent: <?= e($accent) ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <div class="container">
        <?php require __DIR__ . '/partials/site_header.php'; ?>

        <?php if ($dashboardError !== null): ?>
            <div class="message message-error"><?= e($dashboardError) ?></div>
        <?php endif; ?>

        <div class="search-section search-section-spaced">
            <p class="admin-intro">Choose which sources appear on the <a href="<?= e($basePath) ?>/index.php?action=index">Timeline</a>. Toggling a pill reloads the feed with your selection.</p>

            <div class="filter-page-actions">
                <a href="<?= e($basePath) ?>/index.php?action=index" class="btn btn-primary">All</a>
                <a href="<?= e($basePath) ?>/index.php?action=index&amp;none=1" class="btn btn-secondary">None</a>
            </div>

            <form id="dashboard-filters" class="filter-toolbar filter-toolbar--form" method="get" action="<?= e($formAction) ?>">
                <input type="hidden" name="action" value="index">
                <input type="hidden" name="filter_form" value="1">
                <?php if ($searchQuery !== ''): ?>
                    <input type="hidden" name="q" value="<?= e($searchQuery) ?>">
                <?php endif; ?>
                <?php if ($currentView === 'favourites'): ?>
                    <input type="hidden" name="view" value="favourites">
                <?php endif; ?>
                <?php
                $lim = isset($_GET['limit']) && ctype_digit((string)$_GET['limit']) ? (string)$_GET['limit'] : '';
                $off = isset($_GET['offset']) && ctype_digit((string)$_GET['offset']) ? (string)$_GET['offset'] : '';
                ?>
                <?php if ($lim !== ''): ?>
                    <input type="hidden" name="limit" value="<?= e($lim) ?>">
                <?php endif; ?>
                <?php if ($off !== ''): ?>
                    <input type="hidden" name="offset" value="<?= e($off) ?>">
                <?php endif; ?>

                <div class="filter-toolbar__head">
                    <span class="filter-toolbar__label">Filters</span>
                </div>

                <?php if ($filterPillOptions['feed_categories'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Feed</span>
                    <?php foreach ($filterPillOptions['feed_categories'] as $cat): ?>
                        <?php
                        $fcClass = ($cat === 'scraper') ? 'filter-pill-text--scraper' : 'filter-pill-text--feed';
                        $cid    = 'df-feed-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $cat);
                        ?>
                        <label class="filter-pill-label" for="<?= e($cid) ?>">
                            <input type="checkbox" class="filter-pill-input" id="<?= e($cid) ?>"
                                   name="filters[feed][]" value="<?= e($cat) ?>"
                                <?= $feedOn($cat) ? ' checked' : '' ?>>
                            <span class="filter-pill-text <?= e($fcClass) ?>"><?= e($cat) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($filterPillOptions['lex_sources'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Lex</span>
                    <?php foreach ($filterPillOptions['lex_sources'] as $src): ?>
                        <?php $cid = 'df-lex-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $src); ?>
                        <label class="filter-pill-label" for="<?= e($cid) ?>">
                            <input type="checkbox" class="filter-pill-input" id="<?= e($cid) ?>"
                                   name="filters[lex][]" value="<?= e($src) ?>"
                                <?= $lexOn($src) ? ' checked' : '' ?>>
                            <span class="filter-pill-text filter-pill-text--lex"><?= e($src) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($filterPillOptions['email_tags'] !== []): ?>
                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Email tag</span>
                    <?php foreach ($filterPillOptions['email_tags'] as $tg): ?>
                        <?php $cid = 'df-mail-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $tg); ?>
                        <label class="filter-pill-label" for="<?= e($cid) ?>">
                            <input type="checkbox" class="filter-pill-input" id="<?= e($cid) ?>"
                                   name="filters[email][]" value="<?= e($tg) ?>"
                                <?= $mailOn($tg) ? ' checked' : '' ?>>
                            <span class="filter-pill-text filter-pill-text--mail"><?= e($tg) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="filter-toolbar__row">
                    <span class="filter-toolbar__hint">Leg / Jus</span>
                    <label class="filter-pill-label" for="df-cal">
                        <input type="checkbox" class="filter-pill-input" id="df-cal" name="filters[calendar]" value="1"
                            <?= $legOn ? ' checked' : '' ?>>
                        <span class="filter-pill-text filter-pill-text--leg" title="Parliamentary calendar (Leg)">Leg</span>
                    </label>
                    <label class="filter-pill-label" for="df-jus">
                        <input type="checkbox" class="filter-pill-input" id="df-jus" name="filters[jus]" value="1"
                            <?= $jusOn ? ' checked' : '' ?>>
                        <span class="filter-pill-text filter-pill-text--lex" title="Swiss case law (BGer / BGE / BVGE)">Jus</span>
                    </label>
                </div>
            </form>
        </div>
    </div>

    <script>
    (function() {
        var f = document.getElementById('dashboard-filters');
        if (!f) return;
        f.addEventListener('change', function() {
            f.submit();
        });
    })();
    </script>
</body>
</html>
