<?php
/**
 * Settings → Mail tab (mothership IMAP → unified `emails` table).
 *
 * @var string $csrfField
 * @var string $basePath
 * @var array<string, string|null> $mailConfig
 * @var bool $mailPasswordOnFile
 */

declare(strict_types=1);

$mbVal   = trim((string)($mailConfig['mail_imap_mailbox'] ?? ''));
$hostVal = trim((string)($mailConfig['mail_imap_host'] ?? ''));
$portVal = trim((string)($mailConfig['mail_imap_port'] ?? ''));
$flagsVal = trim((string)($mailConfig['mail_imap_flags'] ?? ''));
$folderVal = trim((string)($mailConfig['mail_imap_folder'] ?? ''));
$advancedOpen = $mbVal === '' && ($hostVal !== '' || $portVal !== '' || $flagsVal !== '' || $folderVal !== '');

$maxMsg = (int)($mailConfig['mail_max_messages'] ?? 50);
if ($maxMsg < 1) {
    $maxMsg = 50;
}
$criteriaVal = trim((string)($mailConfig['mail_search_criteria'] ?? ''));
if ($criteriaVal === '') {
    $criteriaVal = 'UNSEEN';
}
$markSeen = ($mailConfig['mail_mark_seen'] ?? '1') === '1' || ($mailConfig['mail_mark_seen'] ?? '') === 'true';

$imapExt = extension_loaded('imap');
?>
        <div class="latest-entries-section">
            <h2 class="section-title">IMAP</h2>
            <p class="admin-intro">
                Ingestion runs inside Seismo when you use <strong>Diagnostics → Refresh all now</strong> or the mothership’s
                <code>refresh_cron.php</code> CLI job. Messages are stored in the unified <code>emails</code> table.
                Newsletter rules and subscriptions stay on the <a href="<?= e($basePath) ?>/index.php?action=mail">Mail</a> page.
            </p>
            <?php if (!$imapExt): ?>
                <p class="message message-warning">
                    The PHP <code>imap</code> extension is not enabled on this server — install or enable <code>ext-imap</code>
                    for fetches to run. You can still save settings here.
                </p>
            <?php endif; ?>
            <p class="admin-intro">
                CLI cron throttles successful mail runs to once per <strong>15 minutes</strong> (other intervals apply to RSS, scraper, etc.).
                A manual web refresh always runs mail immediately when IMAP is configured.
            </p>

            <form method="post" action="<?= e($basePath) ?>/index.php?action=settings_save_mail" class="admin-form-card">
                <?= $csrfField ?>
                <div class="admin-form-field">
                    <label for="mail_imap_mailbox">Mailbox (IMAP string)</label>
                    <input type="text" id="mail_imap_mailbox" name="mail_imap_mailbox" class="search-input" style="width:100%;"
                           value="<?= e($mbVal) ?>"
                           placeholder="{imap.example.com:993/imap/ssl}INBOX"
                           autocomplete="off">
                    <div class="magnitu-field-hint">Leave empty to build the mailbox from host / port / folder below instead.</div>
                </div>

                <details class="settings-mail-advanced"<?= $advancedOpen ? ' open' : '' ?>>
                    <summary>Compose from host, port, and folder</summary>
                    <div class="admin-form-field">
                        <label for="mail_imap_host">Host</label>
                        <input type="text" id="mail_imap_host" name="mail_imap_host" class="search-input" style="width:100%;"
                               value="<?= e($hostVal) ?>" placeholder="mail.example.com" autocomplete="off">
                    </div>
                    <div class="admin-form-field">
                        <label for="mail_imap_port">Port</label>
                        <input type="number" id="mail_imap_port" name="mail_imap_port" class="search-input" style="width:7rem;"
                               value="<?= e($portVal) ?>" placeholder="993" min="1" max="65535">
                    </div>
                    <div class="admin-form-field">
                        <label for="mail_imap_flags">Flags (path after port)</label>
                        <input type="text" id="mail_imap_flags" name="mail_imap_flags" class="search-input" style="width:100%;"
                               value="<?= e($flagsVal) ?>" placeholder="/imap/ssl" autocomplete="off">
                    </div>
                    <div class="admin-form-field">
                        <label for="mail_imap_folder">Folder</label>
                        <input type="text" id="mail_imap_folder" name="mail_imap_folder" class="search-input" style="width:100%;"
                               value="<?= e($folderVal) ?>" placeholder="INBOX" autocomplete="off">
                    </div>
                </details>

                <div class="admin-form-field">
                    <label for="mail_imap_username">Username</label>
                    <input type="text" id="mail_imap_username" name="mail_imap_username" class="search-input" style="width:100%;"
                           value="<?= e(trim((string)($mailConfig['mail_imap_username'] ?? ''))) ?>"
                           placeholder="user@example.com" autocomplete="username">
                </div>
                <div class="admin-form-field">
                    <label for="mail_imap_password">Password</label>
                    <input type="password" id="mail_imap_password" name="mail_imap_password" class="search-input" style="width:100%;"
                           value="" placeholder="Leave blank to keep current password" autocomplete="new-password">
                    <?php if ($mailPasswordOnFile): ?>
                        <div class="magnitu-field-hint">A password is already stored. Enter a new value only to replace it.</div>
                    <?php endif; ?>
                </div>

                <div class="admin-form-field">
                    <label for="mail_search_criteria">Search criteria</label>
                    <input type="text" id="mail_search_criteria" name="mail_search_criteria" class="search-input" style="width:100%;"
                           value="<?= e($criteriaVal) ?>" placeholder="UNSEEN" autocomplete="off">
                    <div class="magnitu-field-hint">Passed to IMAP search (default <code>UNSEEN</code>).</div>
                </div>
                <div class="admin-form-field">
                    <label for="mail_max_messages">Max messages per run</label>
                    <input type="number" id="mail_max_messages" name="mail_max_messages" class="search-input" style="width:7rem;"
                           value="<?= (int)$maxMsg ?>" min="1" max="500">
                </div>
                <div class="admin-form-field">
                    <label><input type="checkbox" name="mail_mark_seen" value="1"<?= $markSeen ? ' checked' : '' ?>> Mark fetched messages as read (\Seen)</label>
                </div>

                <div class="admin-form-actions">
                    <button type="submit" class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
