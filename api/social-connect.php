<?php
/**
 * social-connect.php — Initiate OAuth flow for Instagram or Pinterest
 *
 * GET  ?platform=instagram   → redirect to Meta/Facebook OAuth
 * GET  ?platform=pinterest   → redirect to Pinterest OAuth
 * GET  ?action=status        → return JSON of connected accounts for authed user
 * POST ?action=disconnect    → revoke + delete connection
 *
 * Requires META_APP_ID / META_APP_SECRET and/or
 * PINTEREST_APP_ID / PINTEREST_APP_SECRET in api/config.php.
 */
require_once __DIR__ . '/db.php';
cors();

$platform = strtolower(trim($_GET['platform'] ?? ''));
$action   = strtolower(trim($_GET['action']   ?? ''));

/* ── Status endpoint (GET ?action=status) ─────────────────────────── */
if ($action === 'status') {
    $user = tokenAuth();
    if (!$user) json_out(['success' => false, 'error' => 'unauthenticated'], 401);

    $db   = getDB();
    $stmt = $db->prepare("
        SELECT platform, account_name, account_id, expires_at, extra_data
        FROM social_connections WHERE user_id = ?
    ");
    $stmt->execute([$user['id']]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $expired = $r['expires_at'] && strtotime($r['expires_at']) < time();
        $result[$r['platform']] = [
            'connected'    => !$expired,
            'account_name' => $r['account_name'],
            'account_id'   => $r['account_id'],
            'expires_at'   => $r['expires_at'],
            'boards'       => $r['extra_data'] ? json_decode($r['extra_data'], true)['boards'] ?? [] : [],
        ];
    }
    json_out(['success' => true, 'connections' => $result]);
}

/* ── Disconnect endpoint (POST ?action=disconnect) ────────────────── */
if ($action === 'disconnect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = tokenAuth();
    if (!$user) json_out(['success' => false, 'error' => 'unauthenticated'], 401);

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $plat = strtolower(trim($body['platform'] ?? ''));
    if (!in_array($plat, ['instagram','pinterest'], true))
        json_out(['success' => false, 'error' => 'invalid_platform'], 400);

    $db = getDB();
    $db->prepare("DELETE FROM social_connections WHERE user_id = ? AND platform = ?")
       ->execute([$user['id'], $plat]);

    json_out(['success' => true, 'disconnected' => $plat]);
}

/* ── OAuth initiation (GET ?platform=...) ─────────────────────────── */
if (!in_array($platform, ['instagram', 'pinterest'], true)) {
    http_response_code(400);
    echo 'Invalid platform. Use ?platform=instagram or ?platform=pinterest';
    exit;
}

// token must be passed as ?token= for OAuth state (can't use Authorization header mid-redirect)
$token = trim($_GET['token'] ?? '');
if (!$token) {
    http_response_code(401);
    echo 'Missing ?token= parameter';
    exit;
}

// Store state in session so callback can verify it
session_start();
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state']    = $state;
$_SESSION['oauth_token']    = $token;   // SnapCaptioner auth token
$_SESSION['oauth_platform'] = $platform;

$callback = 'https://snapcaptioner.com/api/social-auth.php';

if ($platform === 'instagram') {
    if (!defined('META_APP_ID') || !META_APP_ID) {
        echo '<h2>Instagram Connect not configured</h2><p>Add META_APP_ID and META_APP_SECRET to api/config.php, then set <code>https://snapcaptioner.com/api/social-auth.php</code> as a Valid OAuth Redirect URI in your Meta App.</p>';
        exit;
    }
    $params = http_build_query([
        'client_id'     => META_APP_ID,
        'redirect_uri'  => $callback,
        'scope'         => 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement',
        'response_type' => 'code',
        'state'         => $state . ':instagram',
    ]);
    header('Location: https://www.facebook.com/v19.0/dialog/oauth?' . $params);
    exit;
}

if ($platform === 'pinterest') {
    if (!defined('PINTEREST_APP_ID') || !PINTEREST_APP_ID) {
        echo '<h2>Pinterest Connect not configured</h2><p>Add PINTEREST_APP_ID and PINTEREST_APP_SECRET to api/config.php, then set <code>https://snapcaptioner.com/api/social-auth.php</code> as a Redirect URI in your Pinterest App.</p>';
        exit;
    }
    $params = http_build_query([
        'client_id'     => PINTEREST_APP_ID,
        'redirect_uri'  => $callback,
        'scope'         => 'boards:read,pins:write',
        'response_type' => 'code',
        'state'         => $state . ':pinterest',
    ]);
    header('Location: https://www.pinterest.com/oauth/?' . $params);
    exit;
}
