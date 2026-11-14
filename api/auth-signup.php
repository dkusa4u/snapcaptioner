<?php
/**
 * SnapCaption AI — POST /api/auth-signup.php
 * Creates a new user account
 *
 * Request body (JSON):
 *   { first_name, last_name?, email, password, plan? }
 *
 * Response (JSON):
 *   Success: { success:true, token, user:{id,name,email,plan}, redirect? }
 *   Error:   { success:false, message }
 */

require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$firstName = trim($body['first_name'] ?? '');
$lastName  = trim($body['last_name']  ?? '');
$email     = strtolower(trim($body['email']    ?? ''));
$password  = $body['password'] ?? '';
$plan      = in_array($body['plan'] ?? '', ['free','premium','teams']) ? $body['plan'] : 'free';

// Validation
if (!$firstName)                        json_out(['success'=>false,'message'=>'First name is required.'], 400);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['success'=>false,'message'=>'Invalid email address.'], 400);
if (strlen($password) < 8)             json_out(['success'=>false,'message'=>'Password must be at least 8 characters.'], 400);

try {
    $db = getDB();

    // Check duplicate email
    $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) {
        json_out(['success'=>false,'message'=>'An account with that email already exists. Try signing in.'], 409);
    }

    // Create user
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $ins  = $db->prepare('INSERT INTO users (first_name, last_name, email, password, plan) VALUES (?,?,?,?,?)');
    $ins->execute([$firstName, $lastName, $email, $hash, $plan]);
    $userId = (int)$db->lastInsertId();

    // Create login token (auto-login after signup)
    $rawToken = bin2hex(random_bytes(TOKEN_BYTES));
    $expires  = date('Y-m-d H:i:s', strtotime('+' . TOKEN_EXPIRY_DAYS . ' days'));
    $tok = $db->prepare('INSERT INTO tokens (user_id, token, expires_at) VALUES (?,?,?)');
    $tok->execute([$userId, $rawToken, $expires]);

    // Redirect logic — send paid-plan signups to pricing page for Stripe checkout.
    // checkout.html does not exist; pricing.html shows the upgrade CTA.
    $redirect = null;
    if ($plan === 'premium') $redirect = 'pricing.html?upgrade=premium';
    if ($plan === 'teams')   $redirect = 'pricing.html?upgrade=teams';

    json_out([
        'success'  => true,
        'token'    => $rawToken,
        'user'     => [
            'id'    => $userId,
            'name'  => $firstName,
            'email' => $email,
            'plan'  => $plan,
        ],
        'redirect' => $redirect,
    ]);

} catch (PDOException $e) {
    error_log('auth-signup: ' . $e->getMessage());
    json_out(['success'=>false,'message'=>'Server error. Please try again.'], 500);
}
