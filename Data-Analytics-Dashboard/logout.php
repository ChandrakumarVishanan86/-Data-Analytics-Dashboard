<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Logout is a POST with a CSRF token so other sites can't log the admin out.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check($_POST['csrf'] ?? null)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

header('Location: index.php');
exit;
