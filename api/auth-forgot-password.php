<?php
/**
 * SnapCaption AI — POST /api/auth-forgot-password.php
 *
 * Accepts: { email: "user@example.com" }
 *
 * Flow:
 *   1. Look up user by email (always respond with the same success message
 *      to prevent email enumeration attacks)
 *   2. Generate a secure 64-char hex token, store in password_reset_tokens
 *      (expires 1 hour from now)
 *   3. Send email with reset link via PHP mail()
 *
 * Response: { success: true, message: "If that email exists, a reset link was sent." }
 */

require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$email = trim(strtolower($body['email'] ?? ''));

// Always return the same message to prevent email enumeration
$genericMsg = 'If that email is registered, a password reset link has been sent.';

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Still 200 so client just shows the generic message
    json_out(['success' => true, 'message' => $genericMsg]);
}

$db = getDB();

// Look up user
$stmt = $db->prepare('SELECT id, first_name FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // No such email — return generic success (do NOT reveal non-existence)
    json_out(['success' => true, 'message' => $genericMsg]);
}

// Delete any existing unused tokens for this user
$db->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used = 0')
   ->execute([$user['id']]);

// Generate a secure token
$token     = bin2hex(random_bytes(32));   // 64-char hex string
$expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour

$ins = $db->prepare('INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)');
$ins->execute([$user['id'], $token, $expiresAt]);

// Build reset URL
$appUrl    = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://snapcaptioner.com';
$resetLink = $appUrl . '/reset-password.html?token=' . urlencode($token);

// Send email via PHP mail()
$firstName = htmlspecialchars($user['first_name'], ENT_QUOTES);
$to        = $email;
$subject   = 'Reset your SnapCaptioner password';
$headers   = implode("\r\n", [
    'From: SnapCaptioner <noreply@snapcaptioner.com>',
    'Reply-To: noreply@snapcaptioner.com',
    'Content-Type: text/html; charset=UTF-8',
    'MIME-Version: 1.0',
    'X-Mailer: PHP/' . PHP_VERSION,
]);

$body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:system-ui,-apple-system,sans-serif;background:#0a0a0f;color:#e5e5e5;margin:0;padding:40px 20px;">
  <div style="max-width:520px;margin:0 auto;background:#111118;border:1px solid #2a2a3a;border-radius:16px;padding:40px;">
    <h1 style="font-size:1.3rem;margin:0 0 8px;color:#fff;">Reset your password</h1>
    <p style="color:#aaa;margin:0 0 24px;font-size:.95rem;">Hi {$firstName}, we received a request to reset your SnapCaptioner password.</p>
    <a href="{$resetLink}"
       style="display:inline-block;background:linear-gradient(135deg,#0891B2,#8B5CF6);color:#fff;text-decoration:none;
              font-weight:600;font-size:.95rem;padding:14px 28px;border-radius:10px;margin-bottom:24px;">
      Reset Password
    </a>
    <p style="color:#666;font-size:.82rem;margin:0 0 8px;">This link expires in 1 hour.</p>
    <p style="color:#666;font-size:.82rem;margin:0;">If you didn't request a password reset, you can safely ignore this email.</p>
    <hr style="border:none;border-top:1px solid #222;margin:28px 0 16px;">
    <p style="color:#444;font-size:.78rem;margin:0;">
      If the button above doesn't work, copy and paste this URL into your browser:<br>
      <span style="color:#666;word-break:break-all;">{$resetLink}</span>
    </p>
  </div>
</body>
</html>
HTML;

$sent = @mail($to, $subject, $body, $headers);

// Even if mail() fails (e.g. SMTP not configured yet), respond success
// so the UI shows the standard message; Dinesh can add SMTP later.
json_out(['success' => true, 'message' => $genericMsg]);
