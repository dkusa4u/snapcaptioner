<?php
/**
 * SnapCaption AI — POST /api/auth-login.php
 * Authenticates an existing user
 *
 * Request body (JSON):
 *   { email, password, remember? }
 *
 * Response (JSON):
 *   Success: { success:true, token, user:{id,name,email,plan} }
 *   Error:   { success:false, message }
 */

require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = strtolower(trim($body['email']    ?? ''));
$password = $body['password'] ?? '';
$remember = !empty($body['remember']);

if (!$email || !$password) {
    json_out(['success'=>false,'message'=>'Email and password are required.'], 400);
}

try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, first_name, email, password, plan FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        // Identical message for both — don't leak whether email exists
        json_out(['success'=>false,'message'=>'Incorrect email or password.'], 401);
    }

    // Issue token
    $days     = $remember ? TOKEN_EXPIRY_DAYS : 1;
    $rawToken = bin2hex(random_bytes(TOKEN_BYTES));
    $expires  = date('Y-m-d H:i:s', strtotime("+{$days} days"));
    $tok = $db->prepare('INSERT INTO tokens (user_id, token, expires_at) VALUES (?,?,?)');
    $tok->execute([$user['id'], $rawToken, $expires]);

    // Re-hash if bcrypt cost has changed
    if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([$newHash, $user['id']]);
    }

    json_out([
        'success' => true,
        'token'   => $rawToken,
        'user'    => [
            'id'    => $user['id'],
            'name'  => $user['first_name'],
            'email' => $user['email'],
            'plan'  => $user['plan'],
        ],
    ]);

} catch (PDOException $e) {
    error_log('auth-login: ' . $e->getMessage());
    json_out(['success'=>false,'message'=>'Server error. Please try again.'], 500);
}
