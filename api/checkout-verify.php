<?php
/**
 * SnapCaption AI — GET /api/checkout-verify.php?session_id=cs_xxx
 * Verifies a Stripe Checkout Session and returns the updated user record.
 *
 * Called by checkout-success.html immediately after redirect from Stripe
 * to refresh the user's plan in localStorage without waiting for the webhook.
 *
 * Config.php must define:
 *   STRIPE_SECRET_KEY   sk_live_...
 *   APP_URL             https://snapcaptioner.com
 */

require_once __DIR__ . '/db.php';
cors();

$sessionId = $_GET['session_id'] ?? '';
if (!$sessionId || !str_starts_with($sessionId, 'cs_')) {
    json_out(['success' => false, 'message' => 'Invalid session ID.'], 400);
}

if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    json_out(['success' => false, 'message' => 'Stripe not configured.'], 503);
}

// ── Retrieve session from Stripe ──────────────────────────────────────────────
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    json_out(['success' => false, 'message' => 'Could not verify payment.'], 502);
}

$session = json_decode($response, true);

if (($session['payment_status'] ?? '') !== 'paid') {
    json_out(['success' => false, 'message' => 'Payment not completed.'], 402);
}

// ── Resolve user ──────────────────────────────────────────────────────────────
$userId     = (int)($session['metadata']['user_id'] ?? 0);
$plan       = $session['metadata']['plan'] ?? '';
$customerId = $session['customer'] ?? null;
$subId      = $session['subscription'] ?? null;

if (!$userId) {
    $email = $session['customer_details']['email'] ?? ($session['customer_email'] ?? '');
    if ($email) {
        $stmt = getDB()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        $userId = $row ? (int)$row['id'] : 0;
    }
}

if (!$userId) {
    json_out(['success' => false, 'message' => 'User not found.'], 404);
}

// Validate plan
$validPlans = ['starter', 'premium', 'pro', 'teams'];
if (!in_array($plan, $validPlans, true)) $plan = 'premium';

// ── Update user in DB ─────────────────────────────────────────────────────────
try {
    $db   = getDB();
    $sets = ['plan = ?'];
    $vals = [$plan];
    if ($customerId) { $sets[] = 'stripe_customer_id = ?'; $vals[] = $customerId; }
    if ($subId)      { $sets[] = 'stripe_sub_id = ?';      $vals[] = $subId; }
    $vals[] = $userId;
    $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
} catch (PDOException $e) {
    error_log('checkout-verify update failed: ' . $e->getMessage());
    json_out(['success' => false, 'message' => 'DB error.'], 500);
}

// ── Return fresh user record ──────────────────────────────────────────────────
$stmt = getDB()->prepare('SELECT first_name, last_name, email, plan FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();

json_out([
    'success' => true,
    'plan'    => $plan,
    'user'    => [
        'id'         => $userId,
        'first_name' => $user['first_name'],
        'last_name'  => $user['last_name'],
        'email'      => $user['email'],
        'plan'       => $plan,
    ],
]);
