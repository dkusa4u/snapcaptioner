<?php
/**
 * SnapCaption AI — POST /api/stripe-webhook.php
 * Handles Stripe webhook events.
 *
 * Register this URL in your Stripe Dashboard → Webhooks:
 *   https://snapcaptioner.com/api/stripe-webhook.php
 *
 * Events handled:
 *   checkout.session.completed         → upgrade user to premium/teams
 *   customer.subscription.deleted      → downgrade user to free
 *   customer.subscription.updated      → sync plan if status changes
 *
 * Config.php must define:
 *   STRIPE_WEBHOOK_SECRET    whsec_...
 */

require_once __DIR__ . '/db.php';

// ── Read raw payload (must happen before any output) ──────────────────────────
$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── Verify signature ─────────────────────────────────────────────────────────
if (!defined('STRIPE_WEBHOOK_SECRET') || !STRIPE_WEBHOOK_SECRET) {
    http_response_code(500);
    exit('Webhook secret not configured');
}

function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    if (!preg_match('/t=(\d+)/', $sigHeader, $tMatch)) return false;
    $timestamp = $tMatch[1];
    // Reject events older than 5 minutes
    if (abs(time() - (int)$timestamp) > 300) return false;

    preg_match_all('/v1=([a-f0-9]+)/', $sigHeader, $vMatches);
    $signatures = $vMatches[1] ?? [];
    if (!$signatures) return false;

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

if (!verifyStripeSignature($payload, $sigHeader, STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (!$event) {
    http_response_code(400);
    exit('Invalid JSON');
}

// ── Helper: update user plan ──────────────────────────────────────────────────
function setUserPlan(int $userId, string $plan): void {
    try {
        $db = getDB();
        $db->prepare('UPDATE users SET plan = ? WHERE id = ?')->execute([$plan, $userId]);
        error_log("stripe-webhook: user $userId → plan $plan");
    } catch (PDOException $e) {
        error_log('stripe-webhook setUserPlan: ' . $e->getMessage());
    }
}

function getUserIdByEmail(string $email): ?int {
    try {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    } catch (PDOException $e) {
        error_log('stripe-webhook getUserIdByEmail: ' . $e->getMessage());
        return null;
    }
}

// ── Handle events ─────────────────────────────────────────────────────────────
$type   = $event['type'] ?? '';
$object = $event['data']['object'] ?? [];

switch ($type) {

    case 'checkout.session.completed':
        // User completed checkout — upgrade their plan
        $userId = (int)($object['metadata']['user_id'] ?? 0);
        $plan   = $object['metadata']['plan'] ?? 'premium';

        if (!$userId) {
            // Fall back to email lookup
            $email = $object['customer_email'] ?? ($object['customer_details']['email'] ?? '');
            if ($email) $userId = getUserIdByEmail($email) ?? 0;
        }

        if ($userId && in_array($plan, ['premium', 'teams'])) {
            setUserPlan($userId, $plan);
        }
        break;

    case 'customer.subscription.deleted':
        // Subscription cancelled — downgrade to free
        $userId = (int)($object['metadata']['user_id'] ?? 0);
        if ($userId) {
            setUserPlan($userId, 'free');
        }
        break;

    case 'customer.subscription.updated':
        // Sync plan on status change (e.g. paused, past_due → active)
        $status = $object['status'] ?? '';
        $userId = (int)($object['metadata']['user_id'] ?? 0);
        if ($userId) {
            if ($status === 'active') {
                // Determine which plan from price ID
                $priceId   = $object['items']['data'][0]['price']['id'] ?? '';
                $teamPrice = defined('STRIPE_TEAMS_PRICE') ? STRIPE_TEAMS_PRICE : '';
                $newPlan   = ($teamPrice && $priceId === $teamPrice) ? 'teams' : 'premium';
                setUserPlan($userId, $newPlan);
            } elseif (in_array($status, ['canceled', 'unpaid', 'paused'])) {
                setUserPlan($userId, 'free');
            }
        }
        break;

    default:
        // Ignore other events
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);
