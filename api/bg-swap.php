<?php
/**
 * SnapCaption AI — Background Swap API
 * Uses OpenAI gpt-image-1 to replace backgrounds
 * Free: 1 swap/day  |  Premium: 5 swaps/day
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/config.php';

// ── Auth ─────────────────────────────────────────────────────────────────────
function getAuthUser(PDO $pdo): ?array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $h, $m)) return null;
    $token = $m[1];
    $stmt = $pdo->prepare("SELECT id, email, plan FROM users WHERE session_token=? AND session_expires > NOW() LIMIT 1");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── DB connection ─────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
    exit;
}

// ── Auto-migrate ──────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS bg_swap_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT  NOT NULL,
    swap_date  DATE NOT NULL,
    count      INT  DEFAULT 1,
    UNIQUE KEY uk_user_date (user_id, swap_date)
)");

// ── Auth check ────────────────────────────────────────────────────────────────
$user = getAuthUser($pdo);
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

$isPrem  = in_array($user['plan'], ['premium', 'teams']);
$limit   = $isPrem ? 5 : 1;
$today   = date('Y-m-d');

// ── GET: usage check ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT count FROM bg_swap_log WHERE user_id=? AND swap_date=?");
    $stmt->execute([$user['id'], $today]);
    $row = $stmt->fetch();
    echo json_encode([
        'used'  => $row ? (int)$row['count'] : 0,
        'limit' => $limit,
        'plan'  => $user['plan']
    ]);
    exit;
}

// ── POST: perform swap ────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    // try multipart
    $body = $_POST;
}

$action = $body['action'] ?? 'swap';

if ($action === 'swap') {
    // Check daily limit
    $stmt = $pdo->prepare("SELECT count FROM bg_swap_log WHERE user_id=? AND swap_date=?");
    $stmt->execute([$user['id'], $today]);
    $row   = $stmt->fetch();
    $used  = $row ? (int)$row['count'] : 0;

    if ($used >= $limit) {
        http_response_code(429);
        echo json_encode([
            'error'   => 'daily_limit_reached',
            'used'    => $used,
            'limit'   => $limit,
            'is_prem' => $isPrem
        ]);
        exit;
    }

    // Get image (base64)
    $imageB64 = $body['image_base64'] ?? '';
    $bgPrompt  = trim($body['bg_prompt'] ?? '');

    if (!$imageB64 || !$bgPrompt) {
        http_response_code(400);
        echo json_encode(['error' => 'missing_params']);
        exit;
    }

    // Decode base64 image
    $imageB64 = preg_replace('#^data:image/\w+;base64,#', '', $imageB64);
    $imgData  = base64_decode($imageB64);
    if (!$imgData || strlen($imgData) < 1000) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_image']);
        exit;
    }

    // Write to temp file
    $tmpImg = tempnam(sys_get_temp_dir(), 'bgswap_') . '.png';
    file_put_contents($tmpImg, $imgData);

    // Build the OpenAI prompt
    $fullPrompt = "Replace ONLY the background in this photo with: {$bgPrompt}. "
        . "Keep the person(s) in the foreground completely unchanged — same position, "
        . "appearance, clothing, and lighting on the subject. The background replacement "
        . "must look photorealistic and natural, blending seamlessly with the subject's lighting.";

    // Call OpenAI Images Edit API
    $curlFile = new CURLFile($tmpImg, 'image/png', 'image.png');

    $ch = curl_init('https://api.openai.com/v1/images/edits');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . OPENAI_KEY,
        ],
        CURLOPT_POSTFIELDS => [
            'model'  => 'gpt-image-1',
            'image[]' => $curlFile,
            'prompt' => $fullPrompt,
            'n'      => '1',
            'size'   => '1024x1024',
        ],
    ]);

    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    @unlink($tmpImg);

    if ($err || $status !== 200) {
        // Fallback: try dall-e-2 if gpt-image-1 fails
        $tmpImg2 = tempnam(sys_get_temp_dir(), 'bgswap2_') . '.png';
        file_put_contents($tmpImg2, $imgData);

        // Try with dall-e-2 (requires 4-channel RGBA PNG for mask, we'll use gpt-image-1 without mask)
        // If gpt-image-1 endpoint fails, return error
        http_response_code(502);
        echo json_encode(['error' => 'openai_error', 'detail' => $err ?: "HTTP $status"]);
        @unlink($tmpImg2);
        exit;
    }

    $data = json_decode($resp, true);

    // gpt-image-1 returns b64_json by default
    $imgResult = $data['data'][0]['b64_json'] ?? null;
    if (!$imgResult) {
        // Try url format
        $imgUrl = $data['data'][0]['url'] ?? null;
        if (!$imgUrl) {
            http_response_code(502);
            echo json_encode(['error' => 'no_image_in_response', 'raw' => $data]);
            exit;
        }
        // Fetch the URL and return as base64
        $imgContent = file_get_contents($imgUrl);
        $imgResult  = base64_encode($imgContent);
    }

    // Log usage (increment or insert)
    $pdo->prepare("INSERT INTO bg_swap_log (user_id, swap_date, count)
                   VALUES (?, ?, 1)
                   ON DUPLICATE KEY UPDATE count = count + 1")
        ->execute([$user['id'], $today]);

    $newUsed = $used + 1;
    echo json_encode([
        'success'      => true,
        'image_base64' => 'data:image/png;base64,' . $imgResult,
        'used'         => $newUsed,
        'limit'        => $limit,
        'remaining'    => $limit - $newUsed
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'unknown_action']);
