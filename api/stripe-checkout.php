<?php
/**
 * SnapCaption AI — POST /api/stripe-checkout.php
 * Creates a Stripe Checkout Session and returns the redirect URL.
 *
 * Request body (JSON):
 *   { plan: 'premium' | 'teams' }
 *
 * Requires Bearer token (user must be logged in).
 *
 * Response (JSON):
 *   { url: 'https://checkout.stripe.com/...' }
 *
 * Config.php must define:
 *   STRIPE_SECRET_KEY       sk_live_...
 *   STRIPE_PREMIUM_PRICE    price_...   ($7/mo)
 *   STRIPE_TEAMS_PRICE      price_...   ($29/mo)
 *   APP_URL                 https://snapcaptioner.com
 */

require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

// ── Auth ─────────────────────────────────────────────────────────────────────
$user = tokenAuth();
if (!$user) {
    json_out(['success' => false, 'message' => 'Please sign in to upgrade.'], 401);
}

// ── Stripe key check ─────────────────────────────────────────────────────────
if (!defined('STRIPE_SECRET_KEY') || !STRIPE_SECRET_KEY) {
    json_out(['success' => false, 'message' => 'Stripe is not configured yet.'], 503);
}

// ── Plan ─────────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$plan = $body['plan'] ?? 'premium';

if ($plan === 'teams') {
    if (!defined('STRIPE_TEAMS_PRICE') || !STRIPE_TEAMS_PRICE) {
        json_out(['success' => false, 'message' => 'Teams plan is not configured yet.'], 503);
    }
    $priceId = STRIPE_TEAMS_PRICE;
} else {
    $plan = 'premium';
    if (!defined('STRIPE_PREMIUM_PRICE') || !STRIPE_PREMIUM_PRICE) {
        json_out(['success' => false, 'message' => 'Premium plan is not configured yet.'], 503);
    }
    $priceId = STRIPE_PREMIUM_PRICE;
}

// ── Create Stripe Checkout Session via cURL ───────────────────────────────────
$successUrl = APP_URL . '/checkout-success.html?session_id={CHECKOUT_SESSION_ID}';
$cancelUrl  = APP_URL . '/checkout-cancel.html';

$payload = http_build_query([
    'mode'                               => 'subscription',
    'line_items[0][price]'               => $priceId,
    'line_items[0][quantity]'            => 1,
    'customer_email'                     => $user['email'],
    'success_url'                        => $successUrl,
    'cancel_url'                         => $cancelUrl,
    'subscription_data[metadata][user_id]' => $user['id'],
    'metadata[user_id]'                  => $user['id'],
    'metadata[plan]'                     => $plan,
    'allow_promotion_codes'              => 'true',
]);

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . STRIPE_SECRET_KEY,
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_TIMEOUT        => 15,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response) {
    error_log('stripe-checkout: cURL failed');
    json_out(['success' => false, 'message' => 'Could not reach Stripe. Try again.'], 502);
}

$stripe = json_decode($response, true);

if ($httpCode !== 200 || empty($stripe['url'])) {
    $errMsg = $stripe['error']['message'] ?? 'Stripe error';
    error_log('stripe-checkout: ' . $errMsg);
    json_out(['success' => false, 'message' => 'Checkout error: ' . $errMsg], 400);
}

json_out(['success' => true, 'url' => $stripe['url']]);
