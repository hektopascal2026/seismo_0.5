<?php
$searchQuery = $searchQuery ?? '';
if (!isset($showFavourites)) {
    $showFavourites = true;
}
$returnQuery = $returnQuery ?? ($_SERVER['QUERY_STRING'] ?? 'action=index');
$showDaySeparators = !empty($showDaySeparators);
$feedLoopPrevDayKey = null;
?>
                <?php foreach ($allItems as $itemWrapper): ?>
                    <?php
                        // Magnitu score data for this entry (badge only, no explanation on index)
                        $entryScore = $itemWrapper['score'] ?? null;
                        $relevanceScore = $entryScore ? (float)$entryScore['relevance_score'] : null;
                        $predictedLabel = $entryScore['predicted_label'] ?? null;
                        $scoreBadgeClass = '';
                        if ($relevanceScore !== null) {
                            $scorePercent = (int)round($relevanceScore * 100);
                            if ($scorePercent <= 25) {
                                $scoreBadgeClass = 'magnitu-badge-noise';
                            } elseif ($scorePercent <= 50) {
                                $scoreBadgeClass = 'magnitu-badge-background';
                            } elseif ($scorePercent <= 75) {
                                $scoreBadgeClass = 'magnitu-badge-important';
                            } else {
                                $scoreBadgeClass = 'magnitu-badge-investigation';
                            }
                        }
                        $favouriteEntryType = $itemWrapper['entry_type'] ?? '';
                        $favouriteEntryId = (int)($itemWrapper['entry_id'] ?? 0);
                        $isFavourite = !empty($itemWrapper['is_favourite']);
                    ?>
                    <?php if ($showDaySeparators): ?>
                        <?php
                            $__ts = (int)($itemWrapper['date'] ?? 0);
                            $__dk = $__ts > 0 ? date('Y-m-d', $__ts) : '';
                            if ($__dk !== '' && ($feedLoopPrevDayKey === null || $feedLoopPrevDayKey !== $__dk)) {
                                $__h = seismo_magnitu_day_heading($__ts);
                                if ($__h !== '') {
                                    echo '<div class="magnitu-day-separator"><span class="magnitu-day-separator-text">' . htmlspecialchars($__h) . '</span></div>';
                                }
                            }
                            if ($__dk !== '') {
                                $feedLoopPrevDayKey = $__dk;
                            }
                        ?>
                    <?php endif; ?>
                    <?php if ($itemWrapper['type'] === 'feed' || $itemWrapper['type'] === 'substack'): ?>
                        <?php $item = $itemWrapper['data']; ?>
                        <?php
                            $itemUrl = seismo_feed_item_resolved_link($item);
                            $fullContent = trim(strip_tags((string) ($item['content'] ?: $item['description'])));
                            if ($fullContent === '' && $itemUrl !== '' && !empty($item['title'])) {
                                $fullContent = trim((string) $item['title']);
                            }
                            $contentPreview = mb_substr($fullContent, 0, 200);
                            if (mb_strlen($fullContent) > 200) {
                                $contentPreview .= '...';
                            }
                            $hasMore = mb_strlen($fullContent) > 200;
                            $feedTagColor = ($itemWrapper['type'] === 'substack') ? 'background-color: #C5B4D1;' : 'background-color: #add8e6;';
                        ?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <?php if (!empty($item['feed_category']) && $item['feed_category'] !== 'unsortiert'): ?>
                                    <span class="entry-tag" style="<?= $feedTagColor ?>"><?= htmlspecialchars($item['feed_category']) ?></span>
                                <?php endif; ?>
                                <?php if ($relevanceScore !== null): ?>
                                    <span class="magnitu-badge <?= $scoreBadgeClass ?>" title="<?= htmlspecialchars($predictedLabel ?? '') ?> (<?= round($relevanceScore * 100) ?>%)"><?= round($relevanceScore * 100) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="entry-title">
                                <?php if ($itemUrl !== ''): ?>
                                    <a href="<?= htmlspecialchars($itemUrl) ?>" target="_blank" rel="noopener">
                                        <?php if (!empty($searchQuery)): ?>
                                            <?= highlightSearchTerm($item['title'], $searchQuery) ?>
                                        <?php else: ?>
                                            <?= htmlspecialchars($item['title']) ?>
                                        <?php endif; ?>
                                    </a>
                                <?php else: ?>
                                    <?php if (!empty($searchQuery)): ?>
                                        <?= highlightSearchTerm($item['title'], $searchQuery) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($item['title']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </h3>
                            <?php if ($fullContent !== ''): ?>
                                <div class="entry-content entry-preview">
                                    <?php 
                                        if (!empty($searchQuery)) {
                                            echo highlightSearchTerm($contentPreview, $searchQuery);
                                        } else {
                                            echo htmlspecialchars($contentPreview);
                                        }
                                    ?>
                                    <?php if ($itemUrl !== ''): ?>
                                        <a href="<?= htmlspecialchars($itemUrl) ?>" target="_blank" rel="noopener" class="entry-link" style="margin-left: 4px;">Read more →</a>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-full-content" style="display:none"><?= htmlspecialchars($fullContent) ?></div>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if ($hasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php if ($item['published_date']): ?>
                                        <span class="entry-date"><?= date('d.m.Y H:i', strtotime($item['published_date'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($showFavourites): ?>
                                    <form method="POST" action="?action=toggle_favourite" class="favourite-form">
                                        <input type="hidden" name="entry_type" value="<?= htmlspecialchars($favouriteEntryType) ?>">
                                        <input type="hidden" name="entry_id" value="<?= $favouriteEntryId ?>">
                                        <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                        <button type="submit" class="favourite-btn<?= $isFavourite ? ' is-favourite' : '' ?>" title="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>" aria-label="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>"><?= $isFavourite ? '★' : '☆' ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($itemWrapper['type'] === 'scraper'): ?>
                        <?php $item = $itemWrapper['data']; ?>
                        <?php
                            $scraperContent = strip_tags($item['content'] ?? '');
                            $scraperPreview = mb_substr($scraperContent, 0, 200);
                            if (mb_strlen($scraperContent) > 200) $scraperPreview .= '...';
                            $scraperHasMore = mb_strlen($scraperContent) > 200;
                        ?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <span class="entry-tag" style="background-color: #FFDBBB; border-color: #000000;">🌐 <?= htmlspecialchars($item['feed_name'] ?? 'Scraper') ?></span>
                                <?php if ($relevanceScore !== null): ?>
                                    <span class="magnitu-badge <?= $scoreBadgeClass ?>" title="<?= htmlspecialchars($predictedLabel ?? '') ?> (<?= round($relevanceScore * 100) ?>%)"><?= round($relevanceScore * 100) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="entry-title">
                                <a href="<?= htmlspecialchars($item['link'] ?? '#') ?>" target="_blank" rel="noopener">
                                    <?= htmlspecialchars($item['title']) ?>
                                </a>
                            </h3>
                            <?php if (!empty($scraperContent)): ?>
                                <div class="entry-content entry-preview">
                                    <?= htmlspecialchars($scraperPreview) ?>
                                    <a href="<?= htmlspecialchars($item['link'] ?? '#') ?>" target="_blank" rel="noopener" class="entry-link" style="margin-left: 4px;">Open page &rarr;</a>
                                </div>
                                <div class="entry-full-content" style="display:none"><?= htmlspecialchars($scraperContent) ?></div>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if ($scraperHasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php if ($item['published_date']): ?>
                                        <span class="entry-date"><?= date('d.m.Y H:i', strtotime($item['published_date'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($showFavourites): ?>
                                    <form method="POST" action="?action=toggle_favourite" class="favourite-form">
                                        <input type="hidden" name="entry_type" value="<?= htmlspecialchars($favouriteEntryType) ?>">
                                        <input type="hidden" name="entry_id" value="<?= $favouriteEntryId ?>">
                                        <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                        <button type="submit" class="favourite-btn<?= $isFavourite ? ' is-favourite' : '' ?>" title="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>" aria-label="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>"><?= $isFavourite ? '★' : '☆' ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($itemWrapper['type'] === 'lex'): ?>
                        <?php $lexItem = $itemWrapper['data']; ?>
                        <?php
                            $lexSource = $lexItem['source'] ?? 'eu';
                            if ($lexSource === 'ch_bger') {
                                $lexSourceEmoji = '⚖️';
                                $lexSourceLabel = 'BGer';
                            } elseif ($lexSource === 'ch_bge') {
                                $lexSourceEmoji = '⚖️';
                                $lexSourceLabel = 'BGE';
                            } elseif ($lexSource === 'ch_bvger') {
                                $lexSourceEmoji = '⚖️';
                                $lexSourceLabel = 'BVGer';
                            } elseif ($lexSource === 'de') {
                                $lexSourceEmoji = '🇩🇪';
                                $lexSourceLabel = 'DE';
                            } elseif ($lexSource === 'ch') {
                                $lexSourceEmoji = '🇨🇭';
                                $lexSourceLabel = 'CH';
                            } elseif ($lexSource === 'parl_mm') {
                                $lexSourceEmoji = '🏛';
                                $lexSourceLabel = 'Parl MM';
                            } elseif ($lexSource === 'fr') {
                                $lexSourceEmoji = '🇫🇷';
                                $lexSourceLabel = 'FR';
                            } else {
                                $lexSourceEmoji = '🇪🇺';
                                $lexSourceLabel = 'EU';
                            }
                            $lexDocType = $lexItem['document_type'] ?? 'Legislation';
                            $lexUrl = $lexItem['eurlex_url'] ?? '#';
                            $lexDate = $lexItem['document_date'] ? date('d.m.Y', strtotime($lexItem['document_date'])) : '';
                            $isJus = in_array($lexSource, ['ch_bger', 'ch_bge', 'ch_bvger']);
                            
                            // For JUS items: parse readable case number from slug
                            $lexCelexDisplay = $lexItem['celex'] ?? '';
                            if ($isJus && preg_match('/^CH_(?:BGer|BGE|BVGE)_\d{3}_(.+)_\d{4}-\d{2}-\d{2}$/', $lexCelexDisplay, $m)) {
                                $rawCn = $m[1];
                                $isBVGer = (strpos($lexCelexDisplay, 'CH_BVGE_') === 0);
                                $lastDash = strrpos($rawCn, '-');
                                if ($lastDash !== false) {
                                    $prefix = substr($rawCn, 0, $lastDash);
                                    $year = substr($rawCn, $lastDash + 1);
                                    $lexCelexDisplay = $isBVGer 
                                        ? $prefix . '/' . $year 
                                        : str_replace('-', ' ', $prefix) . '/' . $year;
                                } else {
                                    $lexCelexDisplay = $rawCn;
                                }
                            }
                            
                            // Link label per source
                            if ($lexSource === 'ch_bger') $lexLinkLabel = 'Entscheid →';
                            elseif ($lexSource === 'ch_bge') $lexLinkLabel = 'Leitentscheid →';
                            elseif ($lexSource === 'ch_bvger') $lexLinkLabel = 'Urteil →';
                            elseif ($lexSource === 'de') $lexLinkLabel = 'recht.bund.de →';
                            elseif ($lexSource === 'ch') $lexLinkLabel = 'Fedlex →';
                            elseif ($lexSource === 'parl_mm') $lexLinkLabel = 'parlament.ch →';
                            elseif ($lexSource === 'fr') $lexLinkLabel = 'Légifrance →';
                            else $lexLinkLabel = 'EUR-Lex →';

                            $lexDesc = trim($lexItem['description'] ?? '');
                            $lexPreview = mb_substr($lexDesc, 0, 300);
                            if (mb_strlen($lexDesc) > 300) $lexPreview .= '...';
                            $lexHasMore = mb_strlen($lexDesc) > 300;
                        ?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <span class="entry-tag" style="background-color: #f5f562; border-color: #000000;"><?= $lexSourceEmoji ?> <?= $lexSourceLabel ?></span>
                                <span class="entry-tag" style="background-color: #f5f5f5;"><?= htmlspecialchars($lexDocType) ?></span>
                                <?php if ($relevanceScore !== null): ?>
                                    <span class="magnitu-badge <?= $scoreBadgeClass ?>" title="<?= htmlspecialchars($predictedLabel ?? '') ?> (<?= round($relevanceScore * 100) ?>%)"><?= round($relevanceScore * 100) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="entry-title">
                                <a href="<?= htmlspecialchars($lexUrl) ?>" target="_blank" rel="noopener">
                                    <?php if (!empty($searchQuery)): ?>
                                        <?= highlightSearchTerm($lexItem['title'], $searchQuery) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($lexItem['title']) ?>
                                    <?php endif; ?>
                                </a>
                            </h3>
                            <?php if (!empty($lexDesc)): ?>
                                <div class="entry-content entry-preview"><?= nl2br(htmlspecialchars($lexPreview)) ?></div>
                                <?php if ($lexHasMore): ?>
                                    <div class="entry-full-content" style="display: none;"><?= nl2br(htmlspecialchars($lexDesc)) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if (!empty($lexDesc) && $lexHasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                    <?php endif; ?>
                                    <?php if ($lexSource !== 'parl_mm'): ?>
                                        <span style="font-family: monospace;<?= $isJus ? ' font-size: 12px; font-weight: 600;' : '' ?>"><?= htmlspecialchars($lexCelexDisplay) ?></span>
                                        <a href="<?= htmlspecialchars($lexUrl) ?>" target="_blank" rel="noopener" class="entry-link"><?= $lexLinkLabel ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php if ($lexDate): ?>
                                        <span class="entry-date"><?= $lexDate ?></span>
                                    <?php endif; ?>
                                    <?php if ($showFavourites): ?>
                                    <form method="POST" action="?action=toggle_favourite" class="favourite-form">
                                        <input type="hidden" name="entry_type" value="<?= htmlspecialchars($favouriteEntryType) ?>">
                                        <input type="hidden" name="entry_id" value="<?= $favouriteEntryId ?>">
                                        <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                        <button type="submit" class="favourite-btn<?= $isFavourite ? ' is-favourite' : '' ?>" title="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>" aria-label="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>"><?= $isFavourite ? '★' : '☆' ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php elseif ($itemWrapper['type'] === 'calendar'): ?>
                        <?php $calEvent = $itemWrapper['data']; ?>
                        <?php
                            $calTypeLabel = getCalendarEventTypeLabel($calEvent['event_type'] ?? '');
                            $calCouncil = getCouncilLabel($calEvent['council'] ?? '');
                            $calUrl = $calEvent['url'] ?? '#';
                            $calEventDate = $calEvent['event_date'] ?? null;
                            $calDaysUntil = $calEventDate ? (int)((strtotime($calEventDate) - strtotime('today')) / 86400) : null;
                            $calDateLabel = '';
                            if ($calEventDate) {
                                $calDateLabel = date('d.m.Y', strtotime($calEventDate));
                                if ($calDaysUntil === 0) $calDateLabel .= ' (today)';
                                elseif ($calDaysUntil === 1) $calDateLabel .= ' (tomorrow)';
                                elseif ($calDaysUntil > 1 && $calDaysUntil <= 14) $calDateLabel .= " (in {$calDaysUntil}d)";
                            }
                            $calDesc = strip_tags($calEvent['description'] ?? '');
                            $calPreview = mb_substr($calDesc, 0, 200);
                            if (mb_strlen($calDesc) > 200) $calPreview .= '...';
                            $calHasMore = mb_strlen($calDesc) > 200;
                            $calMeta = $calEvent['metadata'] ? json_decode($calEvent['metadata'], true) : [];
                        ?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <span class="entry-tag" style="background-color: #d4edda;"><?= htmlspecialchars($calTypeLabel) ?></span>
                                <?php if ($calCouncil): ?>
                                    <span class="entry-tag" style="background-color: #e2e3f1;"><?= htmlspecialchars($calCouncil) ?></span>
                                <?php endif; ?>
                                <?php if ($relevanceScore !== null): ?>
                                    <span class="magnitu-badge <?= $scoreBadgeClass ?>" title="<?= htmlspecialchars($predictedLabel ?? '') ?> (<?= round($relevanceScore * 100) ?>%)"><?= round($relevanceScore * 100) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="entry-title">
                                <a href="<?= htmlspecialchars($calUrl) ?>" target="_blank" rel="noopener">
                                    <?= htmlspecialchars($calEvent['title']) ?>
                                </a>
                            </h3>
                            <?php if ($calDesc): ?>
                                <div class="entry-content entry-preview"><?= htmlspecialchars($calPreview) ?></div>
                                <div class="entry-full-content" style="display:none"><?= htmlspecialchars($calDesc) ?></div>
                            <?php endif; ?>
                            <div class="entry-actions">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if (!empty($calMeta['business_number'])): ?>
                                        <span style="font-family: monospace;"><?= htmlspecialchars($calMeta['business_number']) ?></span>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars($calUrl) ?>" target="_blank" rel="noopener" class="entry-link">parlament.ch &rarr;</a>
                                    <?php if ($calHasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php if ($calDateLabel): ?>
                                        <span class="entry-date"><?= htmlspecialchars($calDateLabel) ?></span>
                                    <?php endif; ?>
                                    <?php if ($showFavourites): ?>
                                    <form method="POST" action="?action=toggle_favourite" class="favourite-form">
                                        <input type="hidden" name="entry_type" value="<?= htmlspecialchars($favouriteEntryType) ?>">
                                        <input type="hidden" name="entry_id" value="<?= $favouriteEntryId ?>">
                                        <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                        <button type="submit" class="favourite-btn<?= $isFavourite ? ' is-favourite' : '' ?>" title="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>" aria-label="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>"><?= $isFavourite ? '★' : '☆' ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php $email = $itemWrapper['data']; ?>
                        <?php
                            $dateValue = $email['date_received'] ?? $email['date_utc'] ?? $email['created_at'] ?? $email['date_sent'] ?? null;
                            $createdAt = $dateValue ? date('d.m.Y H:i', strtotime($dateValue)) : '';
                            
                            $fromName = trim((string)($email['from_name'] ?? ''));
                            $fromEmail = trim((string)($email['from_email'] ?? ''));
                            $fromDisplay = $fromName !== '' ? $fromName : ($fromEmail !== '' ? $fromEmail : 'Unknown sender');

                            $subject = trim((string)($email['subject'] ?? ''));
                            if ($subject === '') $subject = '(No subject)';

                            $body = (string)($email['text_body'] ?? '');
                            if ($body === '') {
                                $body = strip_tags((string)($email['html_body'] ?? ''));
                            }
                            $body = trim(preg_replace('/\s+/', ' ', $body ?? ''));
                            $bodyPreview = mb_substr($body, 0, 200);
                            if (mb_strlen($body) > 200) $bodyPreview .= '...';
                            $hasMore = mb_strlen($body) > 200;
                        ?>
                        <div class="entry-card">
                            <div class="entry-header">
                                <?php if (!empty($email['sender_tag']) && $email['sender_tag'] !== 'unclassified'): ?>
                                    <span class="entry-tag" style="background-color: #FFDBBB;"><?= htmlspecialchars($email['sender_tag']) ?></span>
                                <?php endif; ?>
                                <?php if ($relevanceScore !== null): ?>
                                    <span class="magnitu-badge <?= $scoreBadgeClass ?>" title="<?= htmlspecialchars($predictedLabel ?? '') ?> (<?= round($relevanceScore * 100) ?>%)"><?= round($relevanceScore * 100) ?></span>
                                <?php endif; ?>
                            </div>
                            <h3 class="entry-title">
                                <?php if (!empty($searchQuery)): ?>
                                    <?= highlightSearchTerm($subject, $searchQuery) ?>
                                <?php else: ?>
                                    <?= htmlspecialchars($subject) ?>
                                <?php endif; ?>
                            </h3>
                            <div class="entry-content entry-preview">
                                <?php 
                                    if (!empty($searchQuery)) {
                                        echo highlightSearchTerm($bodyPreview, $searchQuery);
                                    } else {
                                        echo htmlspecialchars($bodyPreview);
                                    }
                                ?>
                            </div>
                            <div class="entry-full-content" style="display:none"><?= htmlspecialchars($body) ?></div>
                            <div class="entry-actions">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <?php if ($hasMore): ?>
                                        <button class="btn btn-secondary entry-expand-btn">expand &#9660;</button>
                                    <?php endif; ?>
                                </div>
                                <div class="entry-meta-right">
                                    <?php if ($createdAt): ?>
                                        <span class="entry-date"><?= htmlspecialchars($createdAt) ?></span>
                                    <?php endif; ?>
                                    <?php if ($showFavourites): ?>
                                    <form method="POST" action="?action=toggle_favourite" class="favourite-form">
                                        <input type="hidden" name="entry_type" value="<?= htmlspecialchars($favouriteEntryType) ?>">
                                        <input type="hidden" name="entry_id" value="<?= $favouriteEntryId ?>">
                                        <input type="hidden" name="return_query" value="<?= htmlspecialchars($returnQuery) ?>">
                                        <button type="submit" class="favourite-btn<?= $isFavourite ? ' is-favourite' : '' ?>" title="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>" aria-label="<?= $isFavourite ? 'Remove from favourites' : 'Add to favourites' ?>"><?= $isFavourite ? '★' : '☆' ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
