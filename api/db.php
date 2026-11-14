<?php
// Guard: if config.php is missing (never committed to git), return a clear JSON error
// instead of a PHP fatal that produces un-parseable HTML.
if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(503);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    echo json_encode([
        'success' => false,
        'error'   => 'server_not_configured',
        'message' => 'Service temporarily unavailable. Please try again shortly.',
    ]);
    exit;
}
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// Run once on first deployment: creates all tables
function initDB(): void {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            first_name   VARCHAR(80)  NOT NULL,
            last_name    VARCHAR(80)  NOT NULL DEFAULT '',
            email        VARCHAR(255) NOT NULL UNIQUE,
            password     VARCHAR(255) NOT NULL,
            plan         ENUM('free','premium','teams') NOT NULL DEFAULT 'free',
            verified     TINYINT(1)   NOT NULL DEFAULT 0,
            brand_voice  TEXT         NULL,
            created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS tokens (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            token      VARCHAR(64)  NOT NULL UNIQUE,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME     NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS captions (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id         INT UNSIGNED NOT NULL,
            platform        VARCHAR(20)  NOT NULL,
            language        VARCHAR(60)  NOT NULL,
            tone            VARCHAR(30)  NOT NULL,
            context_text    TEXT         NULL,
            caption_1       TEXT         NOT NULL,
            caption_2       TEXT         NULL,
            caption_3       TEXT         NULL,
            caption_4       TEXT         NULL,
            caption_5       TEXT         NULL,
            hook_scores     VARCHAR(20)  NULL COMMENT 'comma-separated e.g. 8,7,9,6,8',
            hashtags        TEXT         NULL COMMENT 'JSON array',
            alt_text        TEXT         NULL,
            best_time       VARCHAR(60)  NULL,
            engagement_tip  TEXT         NULL,
            favorited       TINYINT(1)   NOT NULL DEFAULT 0,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS usage_log (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id    INT UNSIGNED NOT NULL,
            action     VARCHAR(30)  NOT NULL COMMENT 'generate | enhance',
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

function tokenAuth(): ?array {
    $headers = getallheaders();
    $bearer  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $bearer, $m)) return null;
    $token = $m[1];

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.plan, u.brand_voice
        FROM tokens t JOIN users u ON t.user_id = u.id
        WHERE t.token = ? AND t.expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function cors(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}
