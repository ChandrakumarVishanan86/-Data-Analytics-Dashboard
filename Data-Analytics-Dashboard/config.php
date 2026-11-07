<?php
/**
 * Global configuration, session start and small helpers.
 * Default WAMP MySQL credentials: user "root" with an empty password.
 *
 * Works on PHP 7.4+ (needs the pdo_mysql and mbstring extensions - both on in WAMP).
 */
declare(strict_types=1);

const DB_HOST  = '127.0.0.1';        // use 127.0.0.1 (faster and safer than "localhost" on Windows)
const DB_PORT  = 3306;               // WAMP: MySQL is usually 3306, MariaDB sometimes 3307. 3306-3308 are tried automatically.
const DB_NAME  = 'analytics_db';
const DB_USER  = 'root';
const DB_PASS  = '';

const APP_NAME = 'Data Analytics Dashboard';
const CURRENCY = 'USD';              // ISO code used to format money in the UI
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB CSV limit
const MAX_UPLOAD_ROWS  = 50000;

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** MySQL error number behind a PDOException (1045 access denied, 1049 unknown DB, 2002 refused ...). */
function db_error_code(Throwable $e): int
{
    if ($e instanceof PDOException && isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
        return (int) $e->errorInfo[1];
    }
    if (preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) || preg_match('/:\s(\d{4})\s/', $e->getMessage(), $m)) {
        return (int) $m[1];
    }
    return is_numeric($e->getCode()) ? (int) $e->getCode() : 0;
}

/** Human readable "what to do" text for a database exception. */
function db_hint(Throwable $e): string
{
    if (!extension_loaded('pdo_mysql')) {
        return 'The PHP extension pdo_mysql is not enabled. In WAMP: left-click the tray icon > PHP > PHP extensions > tick php_pdo_mysql.';
    }
    switch (db_error_code($e)) {
        case 2002:
        case 2003:
        case 2006:
            return 'MySQL is not reachable on ' . DB_HOST . ':' . DB_PORT . '. Start WAMP (tray icon must be green) or set the right DB_PORT in config.php.';
        case 1045:
            return 'MySQL refused the login. Check DB_USER / DB_PASS in config.php (WAMP default: root with empty password).';
        case 1049:
            return 'The database "' . DB_NAME . '" does not exist yet. Run the one-click installer (setup.php).';
        case 1146:
        case 1054:
            return 'The database exists but its tables are missing or outdated. Run the one-click installer (setup.php).';
        default:
            return 'Database error. Open setup.php to run the connection check.';
    }
}

/**
 * Open a PDO connection. $dbname = null connects to the server only (used by the installer).
 * If the configured port refuses the connection, the usual WAMP ports 3306/3307/3308 are tried.
 */
function db_connect(?string $dbname = DB_NAME): PDO
{
    $ports = array_values(array_unique([DB_PORT, 3306, 3307, 3308]));
    $first = null;
    foreach ($ports as $port) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port
             . ($dbname !== null ? ';dbname=' . $dbname : '') . ';charset=utf8mb4';
        try {
            return new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $ex) {
            $first = $first ?? $ex;
            if (db_error_code($ex) !== 2002) {   // only "connection refused" means "try the next port"
                throw $ex;
            }
        }
    }
    throw $first;
}

/** Shared PDO connection to analytics_db (throws PDOException on failure). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect(DB_NAME);
    }
    return $pdo;
}

/** HTML-escape helper. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Inline SVG icon (Feather-style, stroke based). */
function icon(string $name): string
{
    static $paths = [
        'logo'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
        'upload'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'menu'     => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'cart'     => '<circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'dollar'   => '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'trend'    => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'file'     => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    ];
    $inner = $paths[$name] ?? '';
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}
