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
    static $pdo         = null;
    static $initialized = false;
    if ($pdo) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Auto-create tables on first connection so setup.php never needs to be run manually.
    // initDB() calls getDB() which returns $pdo immediately (already set), so no recursion.
    if (!$initialized) {
        $initialized = true;
        try { initDB(); } catch (PDOException $e) {
            error_log('db.php auto-init failed: ' . $e->getMessage());
        }
    }

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
            plan         ENUM('free','starter','premium','pro','teams') NOT NULL DEFAULT 'free',
            verified     TINYINT(1)   NOT NULL DEFAULT 0,
            brand_voice         TEXT         NULL,
            stripe_customer_id  VARCHAR(64)  NULL,
            stripe_sub_id       VARCHAR(64)  NULL,
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

    // Migrate ENUM to include new plans (safe no-op if already migrated)
    migratePlansEnum($db);
    // Migrate users table to add Stripe columns (safe no-op if already present)
    migrateStripeColumns($db);
}

/* ═══════════════════════════════════════════════════════════════════════════
   PLAN HELPER FUNCTIONS
   Single source of truth for all plan limits & feature gates.
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Returns the daily caption generation limit for a plan.
 * null = unlimited
 */
function planCaptionLimit(string $plan): ?int {
    return match($plan) {
        'free'              => 3,
        'starter'           => 15,
        'premium','pro','teams' => null,  // unlimited
        default             => 3,
    };
}

/**
 * Returns the daily AI transform limit for a plan.
 * 0 = not available, null = unlimited
 */
function planTransformLimit(string $plan): ?int {
    return match($plan) {
        'free','starter'    => 0,
        'premium'           => 10,
        'pro','teams'       => 25,
        default             => 0,
    };
}

/**
 * True if the plan has access to paid tools:
 * Brand Voice, Calendar, Repurpose, Reel Script, Tracker.
 */
function planHasTools(string $plan): bool {
    return in_array($plan, ['premium', 'pro', 'teams'], true);
}

/**
 * True if the plan has access to Pro-only features:
 * Bulk, Chrome Extension, Hashtag Research, Multi-brand.
 */
function planIsPro(string $plan): bool {
    return in_array($plan, ['pro', 'teams'], true);
}

/**
 * True if the plan is any paid plan (not free).
 */
function planIsPaid(string $plan): bool {
    return in_array($plan, ['starter', 'premium', 'pro', 'teams'], true);
}

/**
 * Migrate the users table ENUM to include new plans.
 * Safe to call repeatedly — ALTER TABLE is wrapped in try/catch.
 */
function migratePlansEnum(PDO $db): void {
    try {
        $db->exec("ALTER TABLE users MODIFY COLUMN plan ENUM('free','starter','premium','pro','teams') NOT NULL DEFAULT 'free'");
    } catch (PDOException $e) {
        // Already migrated or already correct — ignore
    }
}

function migrateStripeColumns(PDO $db): void {
    try {
        $db->exec("ALTER TABLE users
            ADD COLUMN IF NOT EXISTS stripe_customer_id VARCHAR(64) NULL,
            ADD COLUMN IF NOT EXISTS stripe_sub_id      VARCHAR(64) NULL");
    } catch (PDOException $e) {
        // Already present — ignore
    }
}

function tokenAuth(): ?array {
    $headers = getallheaders();
    $bearer  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $bearer, $m)) return null;
    $token = $m[1];

    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, u.plan, u.brand_voice
            FROM tokens t JOIN users u ON t.user_id = u.id
            WHERE t.token = ? AND t.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        error_log('tokenAuth failed: ' . $e->getMessage());
        return null;
    }
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
