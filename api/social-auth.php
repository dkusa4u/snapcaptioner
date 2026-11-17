<?php
/**
 * social-auth.php — OAuth callback handler for Instagram and Pinterest
 *
 * Meta/Facebook redirects here with ?code=&state=...
 * Pinterest redirects here with ?code=&state=...
 *
 * On success → renders a small HTML page that posts a message to the opener
 * window and closes itself (popup flow).
 */
require_once __DIR__ . '/db.php';
session_start();

function authError(string $msg): never {
    ?><!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"><title>Connect Error</title>
    <style>body{font-family:system-ui;background:#0a0a0f;color:#e5e5e5;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center}</style>
    </head><body>
    <div>
      <h2 style="color:#f87171">❌ Connection Failed</h2>
      <p><?= htmlspecialchars($msg) ?></p>
      <button onclick="window.close()" style="margin-top:16px;padding:8px 20px;background:#a855f7;border:none;border-radius:8px;color:#fff;cursor:pointer">Close</button>
    </div>
    <script>
      window.opener && window.opener.postMessage({type:'social_auth_error',error:<?= json_encode($msg) ?>},'*');
      setTimeout(()=>window.close(),3000);
    </script>
    </body></html>
    <?php exit;
}

function authSuccess(string $platform, string $accountName): never {
    ?><!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"><title>Connected!</title>
    <style>body{font-family:system-ui;background:#0a0a0f;color:#e5e5e5;display:flex;align-items:center;justify-content:center;min-height:100vh;text-align:center}</style>
    </head><body>
    <div>
      <div style="font-size:3rem">✅</div>
      <h2 style="color:#4ade80;margin-top:8px">Connected!</h2>
      <p style="color:#a0a0b8"><?= htmlspecialchars(ucfirst($platform)) ?> account <strong><?= htmlspecialchars($accountName) ?></strong> connected.</p>
      <p style="color:#666;font-size:.85rem;margin-top:8px">This window will close automatically…</p>
    </div>
    <script>
      window.opener && window.opener.postMessage({type:'social_auth_success',platform:<?= json_encode($platform) ?>,account:<?= json_encode($accountName) ?>},'*');
      setTimeout(()=>window.close(),2000);
    </script>
    </body></html>
    <?php exit;
}

/* ── Validate state ───────────────────────────────────────────────── */
$returnedState = $_GET['state'] ?? '';
// state format: <nonce>:<platform>
$parts    = explode(':', $returnedState, 2);
$nonce    = $parts[0] ?? '';
$platform = $parts[1] ?? '';

if (!$nonce || !in_array($platform, ['instagram','pinterest'], true))
    authError('Invalid state parameter.');

$storedState = $_SESSION['oauth_state']    ?? '';
$snapToken   = $_SESSION['oauth_token']    ?? '';
$storedPlat  = $_SESSION['oauth_platform'] ?? '';

if ($nonce !== $storedState || $platform !== $storedPlat)
    authError('State mismatch — possible CSRF. Please try connecting again.');

// Validate the SnapCaptioner token
$user = null;
try {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT u.id, u.first_name, u.plan FROM tokens t
        JOIN users u ON t.user_id = u.id
        WHERE t.token = ? AND t.expires_at > NOW() LIMIT 1
    ");
    $stmt->execute([$snapToken]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    authError('DB error: ' . $e->getMessage());
}

if (!$user) authError('Session expired. Please log in again and retry.');

// Pro/Teams gate
if (!in_array($user['plan'], ['pro','teams'], true))
    authError('Direct publishing requires a Pro or Teams plan.');

// Exchange code
$code     = $_GET['code'] ?? '';
$error    = $_GET['error'] ?? '';
if ($error) authError('OAuth denied: ' . $error);
if (!$code) authError('No authorization code returned.');

$callback = 'https://snapcaptioner.com/api/social-auth.php';

/* ════════════════════════════════════════════════════════════════════
   INSTAGRAM (Meta Graph API)
   ════════════════════════════════════════════════════════════════════ */
if ($platform === 'instagram') {
    if (!defined('META_APP_ID') || !META_APP_ID)
        authError('Instagram not configured on this server.');

    // Step 1: exchange code for short-lived token
    $resp = _postJson('https://graph.facebook.com/v19.0/oauth/access_token', [
        'client_id'     => META_APP_ID,
        'client_secret' => META_APP_SECRET,
        'redirect_uri'  => $callback,
        'code'          => $code,
    ]);
    if (!isset($resp['access_token'])) authError('Meta token exchange failed: ' . json_encode($resp));
    $shortToken = $resp['access_token'];

    // Step 2: exchange for long-lived token (60 days)
    $longResp = _get("https://graph.facebook.com/v19.0/oauth/access_token", [
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => META_APP_ID,
        'client_secret'     => META_APP_SECRET,
        'fb_exchange_token' => $shortToken,
    ]);
    if (!isset($longResp['access_token'])) authError('Long-lived token exchange failed.');
    $longToken  = $longResp['access_token'];
    $expiresIn  = $longResp['expires_in'] ?? (60 * 86400); // default 60 days

    // Step 3: get the user's FB Pages
    $pagesResp = _get("https://graph.facebook.com/v19.0/me/accounts", [
        'access_token' => $longToken,
        'fields'       => 'id,name,instagram_business_account',
    ]);
    $pages = $pagesResp['data'] ?? [];

    // Find first page with an IG business account
    $igAccountId   = null;
    $igAccountName = null;
    $pageId        = null;
    $pageToken     = null;
    foreach ($pages as $page) {
        if (!empty($page['instagram_business_account']['id'])) {
            $igAccountId   = $page['instagram_business_account']['id'];
            $pageId        = $page['id'];
            $pageToken     = $page['access_token'] ?? $longToken;
            // Fetch IG username
            $igInfo = _get("https://graph.facebook.com/v19.0/{$igAccountId}", [
                'fields'       => 'username,name',
                'access_token' => $pageToken,
            ]);
            $igAccountName = $igInfo['username'] ?? $igInfo['name'] ?? 'Instagram';
            break;
        }
    }

    if (!$igAccountId) authError('No Instagram Business/Creator account found linked to your Facebook pages. Make sure your IG account is connected to a Facebook Page as a Business or Creator account.');

    // Save to DB (upsert)
    $expiresAt = date('Y-m-d H:i:s', time() + (int)$expiresIn);
    $db->prepare("
        INSERT INTO social_connections (user_id, platform, access_token, account_id, account_name, page_id, expires_at)
        VALUES (?, 'instagram', ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            access_token = VALUES(access_token),
            account_id   = VALUES(account_id),
            account_name = VALUES(account_name),
            page_id      = VALUES(page_id),
            expires_at   = VALUES(expires_at)
    ")->execute([$user['id'], $pageToken, $igAccountId, $igAccountName, $pageId, $expiresAt]);

    unset($_SESSION['oauth_state'], $_SESSION['oauth_token'], $_SESSION['oauth_platform']);
    authSuccess('instagram', '@' . $igAccountName);
}

/* ════════════════════════════════════════════════════════════════════
   PINTEREST (v5 API)
   ════════════════════════════════════════════════════════════════════ */
if ($platform === 'pinterest') {
    if (!defined('PINTEREST_APP_ID') || !PINTEREST_APP_ID)
        authError('Pinterest not configured on this server.');

    // Exchange code for token
    $resp = _postJson('https://api.pinterest.com/v5/oauth/token', [
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'redirect_uri' => $callback,
    ], [
        'Authorization: Basic ' . base64_encode(PINTEREST_APP_ID . ':' . PINTEREST_APP_SECRET),
    ]);
    if (!isset($resp['access_token'])) authError('Pinterest token exchange failed: ' . json_encode($resp));

    $accessToken = $resp['access_token'];
    $expiresIn   = $resp['expires_in'] ?? (30 * 86400);

    // Get account info
    $meResp = _get('https://api.pinterest.com/v5/user_account', [], [
        'Authorization: Bearer ' . $accessToken,
    ]);
    $pintAccountId   = $meResp['profile_image'] ?? null; // use username as id
    $pintAccountName = $meResp['username'] ?? 'Pinterest';
    $pintAccountId   = $meResp['username'] ?? 'unknown';

    // Get boards list for extra_data
    $boardsResp = _get('https://api.pinterest.com/v5/boards', ['page_size' => 50], [
        'Authorization: Bearer ' . $accessToken,
    ]);
    $boards = [];
    foreach (($boardsResp['items'] ?? []) as $b) {
        $boards[] = ['id' => $b['id'], 'name' => $b['name']];
    }
    $extraData = json_encode(['boards' => $boards]);

    $expiresAt = date('Y-m-d H:i:s', time() + (int)$expiresIn);
    $db->prepare("
        INSERT INTO social_connections (user_id, platform, access_token, account_id, account_name, extra_data, expires_at)
        VALUES (?, 'pinterest', ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            access_token = VALUES(access_token),
            account_id   = VALUES(account_id),
            account_name = VALUES(account_name),
            extra_data   = VALUES(extra_data),
            expires_at   = VALUES(expires_at)
    ")->execute([$user['id'], $accessToken, $pintAccountId, $pintAccountName, $extraData, $expiresAt]);

    unset($_SESSION['oauth_state'], $_SESSION['oauth_token'], $_SESSION['oauth_platform']);
    authSuccess('pinterest', '@' . $pintAccountName);
}

/* ── HTTP helpers ─────────────────────────────────────────────────── */

function _get(string $url, array $params = [], array $extraHeaders = []): array {
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $extraHeaders),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true) ?? [];
}

function _postJson(string $url, array $data, array $extraHeaders = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $extraHeaders),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true) ?? [];
}
