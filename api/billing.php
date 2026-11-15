<?php
/**
 * SnapCaption AI — POST /api/billing.php
 * Creates a Stripe Customer Portal session so users can manage / cancel.
 *
 * Returns: { success: true, url: 'https://billing.stripe.com/...' }
 *
 * Config.php must define:
 *   STRIPE_SECRET_KEY   sk_live_...
 *   APP_URL             https://snapcaptioner.com
 */

require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$user = tokenAuth();
if (!$user) {
    json_out(['success' => false, 'message' => 'Please sign in.'], 401);
}

if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    json_out(['success' => false, 'message' => 'Billing not configured yet.'], 503);
}

// ── Fetch customer ID from DB ─────────────────────────────────────────────────
$stmt = getDB()->prepare('SELECT stripe_customer_id FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
$customerId = $row['stripe_customer_id'] ?? null;

if (!$customerId) {
    json_out([
        'success' => false,
        'message' => 'No active subscription found. Please upgrade first.',
    ], 404);
}

// ── Create Stripe Customer Portal Session ─────────────────────────────────────
$returnUrl = (defined('APP_URL') ? APP_URL : 'https://snapcaptioner.com') . '/app.html';

$ch = curl_init('https://api.stripe.com/v1/billing_portal/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'customer'   => $customerId,
        'return_url' => $returnUrl,
    ]),
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_TIMEOUT => 15,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    error_log('billing.php: portal session failed — ' . $response);
    json_out(['success' => false, 'message' => 'Could not open billing portal.'], 502);
}

$portal = json_decode($response, true);

if (empty($portal['url'])) {
    json_out(['success' => false, 'message' => 'Portal URL missing.'], 500);
}

json_out(['success' => true, 'url' => $portal['url']]);
