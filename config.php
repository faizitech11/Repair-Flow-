<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'repairflow_pro';
const DB_USER = 'root';
const DB_PASS = '';
const APP_NAME = 'RepairFlow Pro';
const MAX_UPLOAD_BYTES = 3_000_000;

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_data(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    if (is_array($json)) return $json;
    return $_POST;
}

function require_auth(): int {
    start_secure_session();
    if (empty($_SESSION['shop_id'])) json_response(['ok' => false, 'message' => 'Please log in again.'], 401);
    return (int) $_SESSION['shop_id'];
}

function require_csrf(): void {
    start_secure_session();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$sent || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        json_response(['ok' => false, 'message' => 'Security token expired. Refresh the page and try again.'], 419);
    }
}

function clean_string(mixed $value, int $max = 255): string {
    $value = trim((string) $value);
    if (mb_strlen($value) > $max) $value = mb_substr($value, 0, $max);
    return $value;
}

function money_value(mixed $value): float {
    if (!is_numeric($value) || (float)$value < 0) throw new InvalidArgumentException('Enter a valid non-negative amount.');
    return round((float)$value, 2);
}
