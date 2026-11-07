<?php
/**
 * One-click installer + connection check.
 *   - Not installed yet : anyone can run the install (nothing to lose).
 *   - Already installed : only a logged-in admin can see details or reinstall.
 * Imports database/analytics_db.sql, so phpMyAdmin is optional.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const SQL_FILE = __DIR__ . '/database/analytics_db.sql';

/** Split a SQL dump into single statements (quote and comment aware). */
function split_sql(string $sql): array
{
    $stmts = [];
    $buf   = '';
    $len   = strlen($sql);
    $q     = null;                       // current quote char, or null

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($q !== null) {
            $buf .= $c;
            if ($c === '\\' && $q !== '`' && $i + 1 < $len) {
                $buf .= $sql[++$i];      // escaped char
            } elseif ($c === $q) {
                if ($i + 1 < $len && $sql[$i + 1] === $q) {
                    $buf .= $sql[++$i];  // doubled quote
                } else {
                    $q = null;
                }
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $buf .= $c;
            continue;
        }
        if ($c === '-' && substr($sql, $i, 3) === '-- ') {   // line comment
            $nl = strpos($sql, "\n", $i);
            $i  = ($nl === false) ? $len : $nl;
            $buf .= "\n";
            continue;
        }
        if ($c === ';') {
            $s = trim($buf);
            if ($s !== '') {
                $stmts[] = $s;
            }
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $s = trim($buf);
    if ($s !== '') {
        $stmts[] = $s;
    }
    return $stmts;
}

/** Create the database and import the dump. Returns the number of statements executed. */
function run_install(): int
{
    $sql = @file_get_contents(SQL_FILE);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('database/analytics_db.sql was not found next to setup.php (or it is empty).');
    }
    $pdo = db_connect(null);
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . DB_NAME . '`');

    $n = 0;
    foreach (split_sql($sql) as $st) {
        if (preg_match('/^(CREATE\s+DATABASE|USE\s|SET\s+NAMES)/i', $st)) {
            continue;                    // handled above with the configured DB_NAME
        }
        $pdo->exec($st);
        $n++;
    }
    return $n;
}

function ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    if ($v === '') {
        return 0;
    }
    $n = (float) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;            // fall through
        case 'm': $n *= 1024;            // fall through
        case 'k': $n *= 1024;
    }
    return (int) $n;
}

/** Run every check; returns ['checks' => [...], 'state' => string, 'counts' => [...]]. */
function inspect_system(): array
{
    $checks = [];
    $state  = 'ready';
    $counts = [];

    $phpOk    = version_compare(PHP_VERSION, '7.4.0', '>=');
    $checks[] = ['PHP version', $phpOk, PHP_VERSION . ($phpOk ? '' : ' - PHP 7.4 or newer is required')];

    $pdoOk    = extension_loaded('pdo_mysql');
    $checks[] = ['PHP extension pdo_mysql', $pdoOk, $pdoOk ? 'enabled' : 'DISABLED - WAMP tray icon > PHP > PHP extensions > php_pdo_mysql'];

    $mbOk     = extension_loaded('mbstring');
    $checks[] = ['PHP extension mbstring', $mbOk, $mbOk ? 'enabled' : 'DISABLED - WAMP tray icon > PHP > PHP extensions > php_mbstring'];

    $up       = min(ini_bytes('upload_max_filesize'), ini_bytes('post_max_size'));
    $upOk     = $up >= MAX_UPLOAD_BYTES;
    $checks[] = ['CSV upload limit (php.ini)', $upOk,
                 round($up / 1048576, 1) . ' MB' . ($upOk ? '' : ' - raise upload_max_filesize and post_max_size to 5M or more')];

    if (!$phpOk || !$pdoOk) {
        return ['checks' => $checks, 'state' => 'noext', 'counts' => $counts];
    }

    // MySQL server
    try {
        $srv      = db_connect(null);
        $ver      = (string) $srv->query('SELECT VERSION()')->fetchColumn();
        $checks[] = ['MySQL server (' . DB_USER . '@' . DB_HOST . ')', true, 'connected - version ' . $ver];
    } catch (Throwable $ex) {
        $checks[] = ['MySQL server (' . DB_USER . '@' . DB_HOST . ':' . DB_PORT . ')', false, db_hint($ex)];
        return ['checks' => $checks, 'state' => 'noserver', 'counts' => $counts];
    }

    // Database
    try {
        $pdo      = db_connect(DB_NAME);
        $checks[] = ['Database ' . DB_NAME, true, 'exists'];
    } catch (Throwable $ex) {
        $checks[] = ['Database ' . DB_NAME, false, 'not created yet'];
        return ['checks' => $checks, 'state' => 'nodb', 'counts' => $counts];
    }

    // Tables
    foreach (['users', 'customers', 'sales_data'] as $t) {
        try {
            $counts[$t] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
            $checks[]   = ['Table ' . $t, true, number_format($counts[$t]) . ' rows'];
        } catch (Throwable $ex) {
            $checks[] = ['Table ' . $t, false, 'missing'];
            $state    = 'notables';
        }
    }
    if ($state === 'ready' && ($counts['users'] ?? 0) < 1) {
        $checks[] = ['Admin account', false, 'no user found'];
        $state    = 'notables';
    }
    return ['checks' => $checks, 'state' => $state, 'counts' => $counts];
}

$info      = inspect_system();
$installed = ($info['state'] === 'ready');
$canSee    = !$installed || is_logged_in();      // details are hidden from visitors once installed
$error     = '';
$done      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif (!in_array($info['state'], ['nodb', 'notables', 'ready'], true)) {
        $error = 'Fix the red items below first.';
    } elseif ($installed && (!is_logged_in() || empty($_POST['confirm']))) {
        $error = 'Sign in as admin and tick the confirmation box to reinstall.';
    } elseif (!$installed && array_sum($info['counts']) > 0 && empty($_POST['confirm'])) {
        $error = 'Some existing tables still contain data. Tick the confirmation box to replace them.';
    } else {
        try {
            $done      = run_install();
            $info      = inspect_system();
            $installed = ($info['state'] === 'ready');
            $canSee    = true;
        } catch (Throwable $ex) {
            $error = 'Install failed: ' . $ex->getMessage();
        }
    }
}

$canInstall = in_array($info['state'], ['nodb', 'notables'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
<main class="setup-shell card">
    <header class="setup-head">
        <div class="brand-mark sm"><?= icon('logo') ?></div>
        <div>
            <h1>Setup &amp; connection check</h1>
            <p class="muted small"><?= e(APP_NAME) ?></p>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($done !== null): ?>
        <div class="alert alert-success" role="status">
            <strong>Database installed.</strong> <?= (int) $done ?> statements ran successfully.
            Sign in with <code>admin</code> / <code>admin123</code>.
        </div>
    <?php endif; ?>

    <?php if ($installed && !$canSee): ?>
        <p>The application is already installed.</p>
        <div class="form-actions"><a class="btn btn-primary" href="index.php">Go to sign in</a></div>
    <?php else: ?>
        <ul class="checks">
            <?php foreach ($info['checks'] as [$label, $ok, $detail]): ?>
                <li class="check-row">
                    <span class="pill <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'FIX' ?></span>
                    <div>
                        <strong><?= e($label) ?></strong>
                        <div class="muted small"><?= e($detail) ?></div>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($info['state'] === 'noserver' || $info['state'] === 'noext'): ?>
            <p class="muted small">Fix the item marked FIX, then reload this page. Database settings live in <code>config.php</code>.</p>
        <?php endif; ?>

        <?php if ($canInstall): ?>
            <form method="post">
                <?= csrf_field() ?>
                <?php if (array_sum($info['counts']) > 0): ?>
                    <label class="check">
                        <input type="checkbox" name="confirm" value="1" required>
                        <span>Some tables already contain data. Replace them with the sample data.</span>
                    </label>
                <?php endif; ?>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Install database now</button>
                </div>
            </form>
            <p class="muted small">Creates <code><?= e(DB_NAME) ?></code> with the tables and sample data from <code>database/analytics_db.sql</code>.</p>
        <?php elseif ($installed): ?>
            <div class="form-actions">
                <a class="btn btn-primary" href="<?= is_logged_in() ? 'dashboard.php' : 'index.php' ?>">
                    <?= is_logged_in() ? 'Open dashboard' : 'Go to sign in' ?>
                </a>
            </div>

            <?php if (is_logged_in()): ?>
                <details class="danger-zone">
                    <summary>Reinstall (deletes all current data)</summary>
                    <form method="post">
                        <?= csrf_field() ?>
                        <label class="check">
                            <input type="checkbox" name="confirm" value="1" required>
                            <span>Yes, drop all tables and restore the sample data</span>
                        </label>
                        <button type="submit" class="btn btn-ghost">Reinstall database</button>
                    </form>
                </details>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
