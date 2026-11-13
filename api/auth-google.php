<?php
/**
 * SnapCaption AI — Google OAuth handler
 *
 * Step 1 (GET, no `code` param): redirect browser to Google consent screen
 * Step 2 (GET, with `code` param): exchange code → token → upsert user → issue session
 *
 * Setup at https://console.cloud.google.com
 *   1. Create OAuth 2.0 Client ID (Web application)
 *   2. Add Authorised redirect URI: https://snapcaption.ai/api/auth-google.php
 *   3. Put GOOGLE_CLIENT_ID + GOOGLE_CLIENT_SECRET in config.php
 */

require_once __DIR__ . '/db.php';

cors();

// ── Kick off OAuth ────────────────────────────────────────────────────────
if (!isset($_GET['code'])) {
    if (!defined('GOOGLE_CLIENT_ID') || GOOGLE_CLIENT_ID === 'your-google-client-id.apps.googleusercontent.com') {
        // Not configured — show friendly setup message
        header('Content-Type: text/html');
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;background:#09090f;color:#e8e8f0">
              <h2>Google Sign-In not configured</h2>
              <p>Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to <code>api/config.php</code></p>
              <p><a href="https://console.cloud.google.com" style="color:#a855f7">Set up Google OAuth →</a></p>
              </body></html>';
        exit;
    }

    $state = bin2hex(random_bytes(16));
    setcookie('oauth_state', $state, time() + 300, '/', '', true, true);

    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => APP_URL . '/api/auth-google.php',
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

// ── Handle callback ───────────────────────────────────────────────────────
$code  = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';

if (!$code || $state !== ($_COOKIE['oauth_state'] ?? '')) {
    redirectWithError('OAuth state mismatch. Please try again.');
}
setcookie('oauth_state', '', time() - 3600, '/');

// Exchange code for tokens
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => APP_URL . '/api/auth-google.php',
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$resp  = curl_exec($ch);
$cErr  = curl_error($ch);
curl_close($ch);

if ($cErr || !$resp) redirectWithError('Could not connect to Google. Please try again.');

$tokens = json_decode($resp, true);
if (empty($tokens['id_token'])) redirectWithError('Google sign-in failed. Please try again.');

// Decode id_token (JWT — verify sig in production via Google certs)
$payload = json_decode(base64_decode(str_pad(explode('.', $tokens['id_token'])[1], 1024, '=')), true);
$email   = strtolower($payload['email'] ?? '');
$name    = $payload['given_name'] ?? 'User';
$last    = $payload['family_name'] ?? '';

if (!$email) redirectWithError('Could not retrieve email from Google.');

try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, plan FROM users WHERE email=? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();

    if ($existing) {
        $userId = $existing['id'];
        $plan   = $existing['plan'];
    } else {
        // New user via Google — create account (no password needed)
        $ins = $db->prepare('INSERT INTO users (first_name, last_name, email, password, plan, verified) VALUES (?,?,?,?,?,1)');
        $ins->execute([$name, $last, $email, '', 'free']);
        $userId = (int)$db->lastInsertId();
        $plan   = 'free';
    }

    $rawToken = bin2hex(random_bytes(TOKEN_BYTES));
    $expires  = date('Y-m-d H:i:s', strtotime('+' . TOKEN_EXPIRY_DAYS . ' days'));
    $db->prepare('INSERT INTO tokens (user_id, token, expires_at) VALUES (?,?,?)')->execute([$userId, $rawToken, $expires]);

    // Pass token back to front-end via URL fragment (stays in browser only)
    $userJson = urlencode(json_encode(['name'=>$name,'email'=>$email,'plan'=>$plan]));
    header("Location: " . APP_URL . "/app.html#token={$rawToken}&user={$userJson}");
    exit;

} catch (PDOException $e) {
    error_log('auth-google: ' . $e->getMessage());
    redirectWithError('Server error. Please try again.');
}

function redirectWithError(string $msg): never {
    header('Location: ' . APP_URL . '/login.html?error=' . urlencode($msg));
    exit;
}
