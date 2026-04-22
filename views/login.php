<?php
/**
 * Login form.
 *
 * @var string  $basePath
 * @var ?string $errorMessage Flash from AuthController::handleLogin().
 */

declare(strict_types=1);

use Seismo\Http\CsrfToken;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= e(seismoBrandTitle()) ?></title>
    <link rel="stylesheet" href="<?= e($basePath) ?>/assets/css/style.css">
</head>
<body>
    <div class="container login-container">
        <div class="top-bar">
            <div class="top-bar-left">
                <span class="top-bar-title">
                    <img src="<?= e($basePath) ?>/assets/img/logo.png" alt="" class="logo-icon logo-icon-large" width="28" height="28" decoding="async">
                    <?= e(seismoBrandTitle()) ?>
                </span>
            </div>
        </div>

        <?php if ($errorMessage !== null): ?>
            <div class="message message-error"><?= e($errorMessage) ?></div>
        <?php endif; ?>

        <div class="latest-entries-section login-form-card">
            <h2 class="section-title">Sign in</h2>
            <form method="post" action="<?= e($basePath) ?>/index.php?action=login">
                <?= CsrfToken::field() ?>
                <div class="admin-form-field">
                    <label>Password<br>
                    <input type="password" name="password" autofocus autocomplete="current-password" class="search-input" style="width:100%;"></label>
                </div>
                <button type="submit" class="btn btn-success">Sign in</button>
            </form>
        </div>
    </div>
</body>
</html>
