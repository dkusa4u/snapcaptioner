<?php
/**
 * social-publish.php — Publish a captioned photo to Instagram or Pinterest
 *
 * POST body (JSON):
 *   platform       "instagram" | "pinterest"
 *   image_url      Public URL of the image to post
 *   caption        Caption text (with hashtags already included)
 *   board_id       Pinterest only — target board id
 *   pin_title      Pinterest only — optional pin title
 *
 * Rate limit:  15 publishes per platform per day (Pro/Teams)
 * Plan gate:   Pro / Teams only
 */
require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$user = tokenAuth();
if (!$user) json_out(['success' => false, 'error' => 'unauthenticated'], 401);

// Pro/Teams gate
if (!in_array($user['plan'], ['pro','teams'], true))
    json_out(['success' => false, 'error' => 'pro_required', 'message' => 'Direct publishing requires Pro or Teams plan.'], 403);

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$plat    = strtolower(trim($body['platform'] ?? ''));
$imgUrl  = trim($body['image_url'] ?? '');
$caption = trim($body['caption']   ?? '');

if (!in_array($plat, ['instagram','pinterest'], true))
    json_out(['success' => false, 'error' => 'invalid_platform'], 400);
if (!$imgUrl)
    json_out(['success' => false, 'error' => 'image_url required'], 400);

// Daily rate limit check (15 per platform per day)
$db      = getDB();
$dayStmt = $db->prepare("
    SELECT COUNT(*) FROM usage_log
    WHERE user_id = ? AND action = ? AND created_at >= CURDATE()
");
$dayStmt->execute([$user['id'], "publish_{$plat}"]);
$dayCount = (int) $dayStmt->fetchColumn();
$LIMIT = ($user['plan'] === 'teams') ? 30 : 15;
if ($dayCount >= $LIMIT)
    json_out(['success' => false, 'error' => 'daily_limit', 'message' => "You've reached your {$LIMIT} daily publishes for " . ucfirst($plat) . ". Resets at midnight."], 429);

// Load connection
$connStmt = $db->prepare("
    SELECT access_token, account_id, page_id, extra_data, expires_at
    FROM social_connections WHERE user_id = ? AND platform = ? LIMIT 1
");
$connStmt->execute([$user['id'], $plat]);
$conn = $connStmt->fetch();

if (!$conn)
    json_out(['success' => false, 'error' => 'not_connected', 'message' => ucfirst($plat) . ' account not connected. Connect it in your dashboard.'], 400);

if ($conn['expires_at'] && strtotime($conn['expires_at']) < time())
    json_out(['success' => false, 'error' => 'token_expired', 'message' => 'Your ' . ucfirst($plat) . ' connection has expired. Please reconnect.'], 401);

/* ════════════════════════════════════════════════════════════════════
   INSTAGRAM — Media Container → Publish
   ════════════════════════════════════════════════════════════════════ */
if ($plat === 'instagram') {
    $igUserId = $conn['account_id'];
    $token    = $conn['access_token'];

    // Step 1: Create media container
    $containerResp = _igPost("https://graph.facebook.com/v19.0/{$igUserId}/media", [
        'image_url'    => $imgUrl,
        'caption'      => $caption,
        'access_token' => $token,
    ]);

    if (empty($containerResp['id']))
        json_out(['success' => false, 'error' => 'container_failed', 'detail' => $containerResp], 500);

    $creationId = $containerResp['id'];

    // Step 2: Wait up to 30 seconds for container to be FINISHED
    $ready  = false;
    $waited = 0;
    while ($waited < 30) {
        $statusResp = _igGet("https://graph.facebook.com/v19.0/{$creationId}", [
            'fields'       => 'status_code',
            'access_token' => $token,
        ]);
        if (($statusResp['status_code'] ?? '') === 'FINISHED') { $ready = true; break; }
        if (($statusResp['status_code'] ?? '') === 'ERROR')
            json_out(['success' => false, 'error' => 'container_error', 'detail' => $statusResp], 500);
        sleep(3);
        $waited += 3;
    }
    if (!$ready)
        json_out(['success' => false, 'error' => 'container_timeout', 'message' => 'Instagram media processing timed out. Try again.'], 500);

    // Step 3: Publish
    $publishResp = _igPost("https://graph.facebook.com/v19.0/{$igUserId}/media_publish", [
        'creation_id'  => $creationId,
        'access_token' => $token,
    ]);

    if (empty($publishResp['id']))
        json_out(['success' => false, 'error' => 'publish_failed', 'detail' => $publishResp], 500);

    // Log usage
    $db->prepare("INSERT INTO usage_log (user_id, action) VALUES (?, 'publish_instagram')")
       ->execute([$user['id']]);

    json_out([
        'success'    => true,
        'platform'   => 'instagram',
        'media_id'   => $publishResp['id'],
        'message'    => 'Posted to Instagram successfully!',
        'remaining'  => $LIMIT - $dayCount - 1,
    ]);
}

/* ════════════════════════════════════════════════════════════════════
   PINTEREST — Create Pin (v5)
   ════════════════════════════════════════════════════════════════════ */
if ($plat === 'pinterest') {
    $token    = $conn['access_token'];
    $boardId  = trim($body['board_id'] ?? '');
    $pinTitle = trim($body['pin_title'] ?? '');

    // If no board provided, pick first board from extra_data
    if (!$boardId && $conn['extra_data']) {
        $extra   = json_decode($conn['extra_data'], true);
        $boards  = $extra['boards'] ?? [];
        $boardId = $boards[0]['id'] ?? '';
    }
    if (!$boardId)
        json_out(['success' => false, 'error' => 'board_required', 'message' => 'No Pinterest board selected.'], 400);

    $pinPayload = [
        'board_id'     => $boardId,
        'title'        => $pinTitle ?: substr($caption, 0, 100),
        'description'  => $caption,
        'media_source' => [
            'source_type' => 'image_url',
            'url'         => $imgUrl,
        ],
    ];

    $pinResp = _pintPost('https://api.pinterest.com/v5/pins', $pinPayload, $token);

    if (empty($pinResp['id']))
        json_out(['success' => false, 'error' => 'pin_failed', 'detail' => $pinResp], 500);

    // Log usage
    $db->prepare("INSERT INTO usage_log (user_id, action) VALUES (?, 'publish_pinterest')")
       ->execute([$user['id']]);

    json_out([
        'success'   => true,
        'platform'  => 'pinterest',
        'pin_id'    => $pinResp['id'],
        'pin_url'   => 'https://www.pinterest.com/pin/' . $pinResp['id'],
        'message'   => 'Pin created on Pinterest!',
        'remaining' => $LIMIT - $dayCount - 1,
    ]);
}

/* ── HTTP helpers ─────────────────────────────────────────────────── */

function _igGet(string $url, array $params): array {
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true) ?? [];
}

function _igPost(string $url, array $data): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true) ?? [];
}

function _pintPost(string $url, array $data, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return json_decode($r, true) ?? [];
}
