<?php
/**
 * Login page  —  default credentials: admin / admin123
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (is_logged_in()) {
    header('Location: dashboard.php');
    exit;
}

$error     = '';
$username  = '';
$showSetup = false;

// Tell the user straight away (before they type anything) if the database is not ready.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        db()->query('SELECT 1 FROM users LIMIT 1');
    } catch (Throwable $ex) {
        $error     = db_hint($ex);
        $showSetup = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim((string) ($_POST['username'] ?? ''));
    $password  = (string) ($_POST['password'] ?? '');
    $now       = time();
    $lockUntil = (int) ($_SESSION['lock_until'] ?? 0);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($now < $lockUntil) {
        $error = 'Too many failed attempts. Try again in ' . ($lockUntil - $now) . ' seconds.';
    } else {
        try {
            $st = db()->prepare('SELECT id, username, password_hash, full_name FROM users WHERE username = ? LIMIT 1');
            $st->execute([$username]);
            $user = $st->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = (int) $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                unset($_SESSION['fails'], $_SESSION['lock_until']);
                header('Location: dashboard.php');
                exit;
            }

            $_SESSION['fails'] = (int) ($_SESSION['fails'] ?? 0) + 1;
            if ($_SESSION['fails'] >= 5) {
                $_SESSION['lock_until'] = $now + 60;
                $_SESSION['fails']      = 0;
            }
            $error = 'Invalid username or password.';
        } catch (PDOException $ex) {
            $error     = db_hint($ex);
            $showSetup = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-brand">
        <div class="brand-mark"><?= icon('logo') ?></div>
        <h1>Know your numbers.</h1>
        <p>Upload sales data, filter by date and watch revenue, categories and top products update instantly.</p>
        <ul class="auth-points">
            <li>Live KPI cards</li>
            <li>Interactive Chart.js charts</li>
            <li>One-click CSV import</li>
        </ul>
    </section>

    <section class="auth-card">
        <h2>Welcome back</h2>
        <p class="muted">Sign in to open your dashboard.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert">
                <?= e($error) ?>
                <?php if ($showSetup): ?>
                    <br><a href="setup.php"><strong>Open setup / connection check &rarr;</strong></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="on" novalidate>
            <?= csrf_field() ?>

            <label class="field">
                <span>Username</span>
                <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
            </label>

            <label class="field">
                <span>Password</span>
                <div class="password-wrap">
                    <input type="password" name="password" id="password" autocomplete="current-password" required>
                    <button type="button" class="link-btn" id="togglePw" aria-label="Show password">Show</button>
                </div>
            </label>

            <button type="submit" class="btn btn-primary btn-block">Sign in</button>
        </form>

        <p class="hint">Demo login: <code>admin</code> / <code>admin123</code></p>
    </section>
</main>

<script>
    const pw = document.getElementById('password');
    const tg = document.getElementById('togglePw');
    tg.addEventListener('click', () => {
        const show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        tg.textContent = show ? 'Hide' : 'Show';
        tg.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
</script>
</body>
</html>
