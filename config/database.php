<?php
/**
 * EDMS - Database Configuration
 * MS SQL Server connection using PDO with SQLSRV driver
 */

define('DB_SERVER', 'localhost');
define('DB_NAME', 'edms');
define('DB_USER', 'sa');
define('DB_PASS', 'LineAnalytics@2025');
define('DB_USE_WINDOWS_AUTH', false);

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
