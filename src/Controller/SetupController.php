<?php

declare(strict_types=1);

namespace Seismo\Controller;

use PDO;
use PDOException;
use Seismo\Http\CsrfToken;

/**
 * First-run / recovery helper for `config.local.php` (Slice 9 stub).
 *
 * - Tests MariaDB credentials with PDO before offering to write the file.
 * - If the install directory is not writable (or the write fails), shows a
 *   copy-and-paste block — never chmod 0777, never /tmp.
 * - On successful write, redirects to {@see HealthController} for verification.
 */
final class SetupController
{
    public function show(): void
    {
        $this->renderView([
            'formError'       => null,
            'writeNote'       => null,
            'copyPasteBody'   => null,
            'dbTestOk'        => null,
            'dbTestMessage'   => null,
            'old'             => $this->defaultOld(),
        ]);
    }

    public function handlePost(): void
    {
        if (!CsrfToken::verifyRequest()) {
            $_SESSION['error'] = 'Session expired — please try again.';
            header('Location: ' . getBasePath() . '/index.php?action=setup', true, 303);
            exit;
        }

        $old = $this->readOldFromPost();

        $err = $this->validateOld($old);
        if ($err !== null) {
            $this->renderView([
                'formError'     => $err,
                'writeNote'     => null,
                'copyPasteBody' => null,
                'dbTestOk'      => false,
                'dbTestMessage' => null,
                'old'           => $old,
            ]);

            return;
        }

        $pdoTest = $this->testDatabase($old);
        if (!$pdoTest['ok']) {
            $this->renderView([
                'formError'     => null,
                'writeNote'     => null,
                'copyPasteBody' => null,
                'dbTestOk'      => false,
                'dbTestMessage' => (string)$pdoTest['message'],
                'old'           => $old,
            ]);

            return;
        }

        $body = $this->buildConfigBody($old);
        $path = SEISMO_ROOT . '/config.local.php';

        $canWrite = (!is_file($path) && is_writable(SEISMO_ROOT))
            || (is_file($path) && is_writable($path));

        if (!$canWrite) {
            $this->renderView([
                'formError'       => null,
                'writeNote'       => 'This directory is not writable by PHP, or the existing file cannot be overwritten. Save the block below manually as config.local.php in your install root, then open Health.',
                'copyPasteBody'   => $body,
                'dbTestOk'        => true,
                'dbTestMessage'   => (string)$pdoTest['message'],
                'old'             => $old,
            ]);

            return;
        }

        $written = @file_put_contents($path, $body, LOCK_EX);
        if ($written === false) {
            $this->renderView([
                'formError'       => null,
                'writeNote'       => 'file_put_contents() failed (permissions or disk). Paste the generated file manually — do not loosen permissions to 0777.',
                'copyPasteBody'   => $body,
                'dbTestOk'        => true,
                'dbTestMessage'   => (string)$pdoTest['message'],
                'old'             => $old,
            ]);

            return;
        }

        header('Location: ' . getBasePath() . '/index.php?action=health', true, 303);
        exit;
    }

    /**
     * @param array{
     *   formError: ?string,
     *   writeNote: ?string,
     *   copyPasteBody: ?string,
     *   dbTestOk: ?bool,
     *   dbTestMessage: ?string,
     *   old: array<string, string>
     * } $ctx
     */
    private function renderView(array $ctx): void
    {
        $csrfField = CsrfToken::field();
        $basePath   = getBasePath();
        $accent     = seismoBrandAccent();

        require_once SEISMO_ROOT . '/views/helpers.php';

        $headerTitle    = seismoBrandTitle();
        $headerSubtitle = 'First-run configuration';
        $activeNav      = 'setup';

        extract($ctx, EXTR_OVERWRITE);

        require SEISMO_ROOT . '/views/setup.php';
    }

    /** @return array<string, string> */
    private function defaultOld(): array
    {
        return [
            'db_host' => 'localhost',
            'db_port' => '',
            'db_name' => '',
            'db_user' => '',
            'db_pass' => '',
            'migrate_key' => '',
            'admin_password' => '',
        ];
    }

    /** @return array<string, string> */
    private function readOldFromPost(): array
    {
        $g = static fn (string $k): string => trim((string)($_POST[$k] ?? ''));

        return [
            'db_host'         => $g('db_host') !== '' ? $g('db_host') : 'localhost',
            'db_port'         => $g('db_port'),
            'db_name'         => $g('db_name'),
            'db_user'         => $g('db_user'),
            'db_pass'         => (string)($_POST['db_pass'] ?? ''),
            'migrate_key'     => $g('migrate_key'),
            'admin_password'  => (string)($_POST['admin_password'] ?? ''),
        ];
    }

    /** @param array<string, string> $old */
    private function validateOld(array $old): ?string
    {
        if ($old['db_name'] === '' || $old['db_user'] === '') {
            return 'Database name and user are required.';
        }

        return null;
    }

    /**
     * @param array<string, string> $old
     * @return array{ok: bool, message: string}
     */
    private function testDatabase(array $old): array
    {
        $host = $old['db_host'];
        $port = $old['db_port'] !== '' ? (int)$old['db_port'] : null;
        if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
            $host = $m[1];
            $port = (int)$m[2];
        }

        $dsn = 'mysql:host=' . $host . ';dbname=' . $old['db_name'] . ';charset=utf8mb4';
        if ($port !== null && $port > 0) {
            $dsn .= ';port=' . $port;
        }

        try {
            $pdo = new PDO($dsn, $old['db_user'], $old['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET time_zone = '+00:00'");
            $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();

            return ['ok' => true, 'message' => 'Connected to MySQL ' . $ver . ' (session time zone set to UTC).'];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'Database connection failed: ' . $e->getMessage()];
        }
    }

    /**
     * @param array<string, string> $old
     */
    private function buildConfigBody(array $old): string
    {
        $lines   = [];
        $lines[] = '<?php';
        $lines[] = '/**';
        $lines[] = ' * Local credentials — generated by Seismo ?action=setup';
        $lines[] = ' * ' . gmdate('Y-m-d\\TH:i:s\\Z') . ' — review before committing to source control.';
        $lines[] = ' */';
        $lines[] = '';
        $lines[] = 'define(\'DB_HOST\', ' . var_export($old['db_host'], true) . ');';
        $lines[] = 'define(\'DB_NAME\', ' . var_export($old['db_name'], true) . ');';
        $lines[] = 'define(\'DB_USER\', ' . var_export($old['db_user'], true) . ');';
        $lines[] = 'define(\'DB_PASS\', ' . var_export($old['db_pass'], true) . ');';
        if ($old['db_port'] !== '') {
            $lines[] = 'define(\'DB_PORT\', ' . var_export($old['db_port'], true) . ');';
        }
        $lines[] = '';

        if ($old['migrate_key'] !== '') {
            $lines[] = 'define(\'SEISMO_MIGRATE_KEY\', ' . var_export($old['migrate_key'], true) . ');';
            $lines[] = '';
        }

        if ($old['admin_password'] !== '') {
            $hash = password_hash($old['admin_password'], PASSWORD_DEFAULT);
            if ($hash === false) {
                $hash = '';
            }
            if ($hash !== '') {
                $lines[] = 'define(\'SEISMO_ADMIN_PASSWORD_HASH\', ' . var_export($hash, true) . ');';
                $lines[] = '';
            }
        }

        $lines[] = '// Optional: SEISMO_VIEW_TIMEZONE, satellite knobs, export:api_key via Settings UI after first login.';

        return implode("\n", $lines) . "\n";
    }
}
