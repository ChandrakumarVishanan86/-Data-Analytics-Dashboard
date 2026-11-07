<?php
/**
 * Authentication + CSRF helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * Guard a page. Pass $json = true for API endpoints so they answer with 401 JSON
 * instead of redirecting. $loginPath is the path to index.php from the caller.
 */
function require_login(bool $json = false, string $loginPath = 'index.php'): void
{
    if (is_logged_in()) {
        return;
    }
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Not authenticated']);
    } else {
        header('Location: ' . $loginPath);
    }
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $token);
}
