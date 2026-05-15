<?php
/**
 * EDMS - Database Configuration
 * MS SQL Server connection using PDO with SQLSRV driver
 *
 * Production (uncle's XAMPP box) uses SQL auth with the sa account.
 * Local dev (this machine) uses Windows auth against SQLEXPRESS.
 *
 * To override any of these constants without editing this file, create
 * config/config.local.php (gitignored) and define() the values BEFORE this
 * file's defines run. Example:
 *     <?php
 *     define('DB_SERVER', 'localhost\\SQLEXPRESS');
 *     define('DB_USE_WINDOWS_AUTH', true);
 */

// Load local overrides first so they win.
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Production defaults (only used if not already defined by config.local.php).
if (!defined('DB_SERVER'))           define('DB_SERVER', 'localhost');
if (!defined('DB_NAME'))             define('DB_NAME', 'edms');
if (!defined('DB_USER'))             define('DB_USER', 'sa');
if (!defined('DB_PASS'))             define('DB_PASS', 'LineAnalytics@2025');
if (!defined('DB_USE_WINDOWS_AUTH')) define('DB_USE_WINDOWS_AUTH', false);

define('APP_NAME', 'EDMS');
define('APP_FULL_NAME', 'Engineering Drawing Management System');
define('APP_VERSION', '1.0.0');

define('BASE_URL', '/edms');
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('OUTPUT_PATH', __DIR__ . '/../outputs/');
define('TEMPLATE_PATH', __DIR__ . '/../uploads/templates/');
define('ODA_FILE_CONVERTER', 'C:\\Program Files\\ODA\\ODAFileConverter\\ODAFileConverter.exe');
define('ODA_OUTPUT_VERSION', 'ACAD2018'); // Output DWG version when round-tripping

// Session configuration (must be set before session_start)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
}

/**
 * Get PDO database connection
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            if (DB_USE_WINDOWS_AUTH) {
                $dsn = "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME . ";TrustServerCertificate=yes";
                $pdo = new PDO($dsn);
            } else {
                $dsn = "sqlsrv:Server=" . DB_SERVER . ";Database=" . DB_NAME . ";TrustServerCertificate=yes";
                $pdo = new PDO($dsn, DB_USER, DB_PASS);
            }
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection failed. Please check configuration.");
        }
    }
    return $pdo;
}

/**
 * Generate a new UUID
 */
function generateUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

/**
 * Sanitize input
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Canonical SO folder name. Use this everywhere we build a per-SO output dir
 * so the same SO doesn't end up split across e.g. 'SO-2026-1' and 'SO_2026_1'.
 */
function safeSoFolder(string $so_number): string {
    $trim = trim($so_number);
    if ($trim === '') return 'SO_UNKNOWN';
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $trim);
}

/**
 * Canonical filesystem base name for any user-provided string used in a path.
 */
function safeFileName(string $name): string {
    $trim = trim($name);
    $clean = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $trim);
    return $clean !== '' ? $clean : 'file_' . bin2hex(random_bytes(4));
}

/**
 * JSON response helper
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Flash message helper
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
