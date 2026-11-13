<?php
/**
 * SnapCaption AI — Apple Sign In handler (stub)
 *
 * Apple OAuth is significantly more complex than Google:
 *   - Requires Apple Developer account ($99/yr)
 *   - Must generate a client_secret JWT from a .p8 private key
 *   - User email only returned on FIRST login
 *
 * Setup steps:
 *   1. Create App ID with Sign In with Apple capability
 *   2. Create Services ID → configure domain + redirect URI
 *   3. Download .p8 auth key → store securely outside webroot
 *   4. Add to config.php:
 *      APPLE_CLIENT_ID, APPLE_TEAM_ID, APPLE_KEY_ID, APPLE_P8_PATH
 *
 * For now: redirect to login with a helpful message
 */

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'signin';
$page   = $action === 'signup' ? 'signup' : 'login';

header('Location: ' . APP_URL . '/' . $page . '.html?error=' . urlencode('Apple Sign In coming soon. Please use email or Google.'));
exit;
