<?php
/**
 * SnapCaption AI — POST /api/auth-reset-password.php
 *
 * Accepts: { token: "64hexchars", password: "newpassword" }
 *
 * Flow:
 *   1. Validate token is not expired and not used
 *   2. Hash and update user's password
 *   3. Mark token as used
 *   4. Invalidate all existing auth tokens for security
 *
 * Response: { success: true, message: "Password updated successfully." }
 *       or: { success: false, message: "..." }
 */

require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$token    = trim($body['token']    ?? '');
$password = trim($body['password'] ?? '');

if (!$token || strlen($token) !== 64) {
    json_out(['success' => false, 'message' => 'Invalid or missing reset token.'], 400);
}

if (!$password || strlen($password) < 8) {
    json_out(['success' => false, 'message' => 'Password must be at least 8 characters.'], 400);
}

$db = getDB();

// Look up the token
$stmt = $db->prepare(
    'SELECT prt.id, prt.user_id, prt.expires_at, prt.used
     FROM password_reset_tokens prt
     WHERE prt.token = ?
     LIMIT 1'
);
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    json_out(['success' => false, 'message' => 'Invalid reset link. Please request a new one.'], 400);
}

if ($row['used']) {
    json_out(['success' => false, 'message' => 'This reset link has already been used. Please request a new one.'], 400);
}

if (strtotime($row['expires_at']) < time()) {
    json_out(['success' => false, 'message' => 'This reset link has expired. Please request a new one.'], 400);
}

$userId   = (int)$row['user_id'];
$hash     = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// Update password
$db->prepare('UPDATE users SET password = ? WHERE id = ?')
   ->execute([$hash, $userId]);

// Mark token as used
$db->prepare('UPDATE password_reset_tokens SET used = 1 WHERE id = ?')
   ->execute([$row['id']]);

// Invalidate all existing auth tokens for this user (force re-login)
$db->prepare('DELETE FROM tokens WHERE user_id = ?')
   ->execute([$userId]);

json_out(['success' => true, 'message' => 'Password updated successfully. You can now log in.']);
