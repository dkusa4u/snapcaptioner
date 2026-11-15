<?php
/**
 * SnapCaption AI — POST /api/stripe-webhook.php
 * Handles Stripe webhook events to keep user plans in sync.
 *
 * Register this URL in your Stripe Dashboard → Webhooks:
 *   https://snapcaptioner.com/api/stripe-webhook.php
 *
 * Events handled:
 *   checkout.session.completed         → upgrade user plan
 *   customer.subscription.updated      → sync plan on status change
 *   customer.subscription.deleted      → downgrade to free
 *   invoice.payment_failed             → (logged, plan left active for grace period)
 *
 * Config.php must define:
 *   STRIPE_WEBHOOK_SECRET    whsec_...
 *   STRIPE_STARTER_PRICE     price_...
 *   STRIPE_PREMIUM_PRICE     price_...
 *   STRIPE_PRO_PRICE         price_...
 *   STRIPE_TEAMS_PRICE       price_...
 */

require_once __DIR__ . '/db.php';

// ── Read raw payload BEFORE any output ───────────────────────────────────────
$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── Verify signature ─────────────────────────────────────────────────────────
if (!defined('STRIPE_WEBHOOK_SECRET') || !STRIPE_WEBHOOK_SECRET) {
    http_response_code(500);
    exit('Webhook secret not configured');
}

function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    if (!preg_match('/t=(\d+)/', $sigHeader, $tMatch)) return false;
    $timestamp = (int)$tMatch[1];
    if (abs(time() - $timestamp) > 300) return false; // reject events > 5 min old

    preg_match_all('/v1=([a-f0-9]+)/', $sigHeader, $vMatches);
    $signatures = $vMatches[1] ?? [];
    if (!$signatures) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
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
if (!$event) { http_response_code(400); exit('Invalid JSON'); }

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Map a Stripe price ID to our plan slug.
 * Falls back to $fallbackPlan if no price IDs are configured.
 */
function priceIdToPlan(string $priceId, string $fallbackPlan = 'premium'): string {
    $map = [];
    if (defined('STRIPE_STARTER_PRICE') && STRIPE_STARTER_PRICE) $map[STRIPE_STARTER_PRICE] = 'starter';
    if (defined('STRIPE_PREMIUM_PRICE') && STRIPE_PREMIUM_PRICE) $map[STRIPE_PREMIUM_PRICE] = 'premium';
    if (defined('STRIPE_PRO_PRICE')     && STRIPE_PRO_PRICE)     $map[STRIPE_PRO_PRICE]     = 'pro';
    if (defined('STRIPE_TEAMS_PRICE')   && STRIPE_TEAMS_PRICE)   $map[STRIPE_TEAMS_PRICE]   = 'teams';

    // Also check yearly price IDs if defined
    if (defined('STRIPE_STARTER_PRICE_YEARLY') && STRIPE_STARTER_PRICE_YEARLY) $map[STRIPE_STARTER_PRICE_YEARLY] = 'starter';
    if (defined('STRIPE_PREMIUM_PRICE_YEARLY') && STRIPE_PREMIUM_PRICE_YEARLY) $map[STRIPE_PREMIUM_PRICE_YEARLY] = 'premium';
    if (defined('STRIPE_PRO_PRICE_YEARLY')     && STRIPE_PRO_PRICE_YEARLY)     $map[STRIPE_PRO_PRICE_YEARLY]     = 'pro';
    if (defined('STRIPE_TEAMS_PRICE_YEARLY')   && STRIPE_TEAMS_PRICE_YEARLY)   $map[STRIPE_TEAMS_PRICE_YEARLY]   = 'teams';

    return $map[$priceId] ?? $fallbackPlan;
}

/** Update user plan + optionally store Stripe IDs. */
function setUserPlan(int $userId, string $plan, ?string $customerId = null, ?string $subId = null): void {
    try {
        $db = getDB();
        if ($customerId || $subId) {
            $sets  = ['plan = ?'];
            $vals  = [$plan];
            if ($customerId) { $sets[] = 'stripe_customer_id = ?'; $vals[] = $customerId; }
            if ($subId)      { $sets[] = 'stripe_sub_id = ?';      $vals[] = $subId; }
            $vals[] = $userId;
            $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        } else {
            $db->prepare('UPDATE users SET plan = ? WHERE id = ?')->execute([$plan, $userId]);
        }
        error_log("stripe-webhook: user $userId → plan $plan");
    } catch (PDOException $e) {
        error_log('stripe-webhook setUserPlan: ' . $e->getMessage());
    }
}

/** Look up user by email. */
function getUserIdByEmail(string $email): ?int {
    try {
        $stmt = getDB()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    } catch (PDOException $e) {
        error_log('stripe-webhook getUserIdByEmail: ' . $e->getMessage());
        return null;
    }
}

/** Look up user by Stripe customer ID. */
function getUserIdByCustomer(string $customerId): ?int {
    try {
        $stmt = getDB()->prepare('SELECT id FROM users WHERE stripe_customer_id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    } catch (PDOException $e) {
        return null;
    }
}

// ── Handle events ─────────────────────────────────────────────────────────────
$type   = $event['type']          ?? '';
$object = $event['data']['object'] ?? [];

switch ($type) {

    // ── Payment completed: upgrade plan ───────────────────────────────────────
    case 'checkout.session.completed':
        $userId     = (int)($object['metadata']['user_id'] ?? 0);
        $plan       = $object['metadata']['plan'] ?? '';
        $customerId = $object['customer'] ?? null;
        $subId      = $object['subscription'] ?? null;

        // Fallback: resolve user via email if metadata missing
        if (!$userId) {
            $email = $object['customer_details']['email'] ?? ($object['customer_email'] ?? '');
            if ($email) $userId = getUserIdByEmail($email) ?? 0;
        }

        // Resolve plan from price if metadata missing
        if (!$plan || !in_array($plan, ['starter', 'premium', 'pro', 'teams'], true)) {
            // Try to get price from line items (not always available here; rely on metadata)
            $plan = 'premium'; // safe fallback
        }

        if ($userId && in_array($plan, ['starter', 'premium', 'pro', 'teams'], true)) {
            setUserPlan($userId, $plan, $customerId, $subId);
        }
        break;

    // ── Subscription updated: sync plan ──────────────────────────────────────
    case 'customer.subscription.updated':
        $status     = $object['status'] ?? '';
        $customerId = $object['customer'] ?? null;
        $subId      = $object['id'] ?? null;
        $userId     = (int)($object['metadata']['user_id'] ?? 0);

        if (!$userId && $customerId) {
            $userId = getUserIdByCustomer($customerId) ?? 0;
        }

        if ($userId) {
            if ($status === 'active') {
                // Determine plan from price ID
                $priceId = $object['items']['data'][0]['price']['id'] ?? '';
                $newPlan = $priceId ? priceIdToPlan($priceId) : 'premium';
                setUserPlan($userId, $newPlan, $customerId, $subId);
            } elseif (in_array($status, ['canceled', 'unpaid', 'paused'], true)) {
                setUserPlan($userId, 'free');
            }
        }
        break;

    // ── Subscription cancelled: downgrade to free ─────────────────────────────
    case 'customer.subscription.deleted':
        $customerId = $object['customer'] ?? null;
        $userId     = (int)($object['metadata']['user_id'] ?? 0);

        if (!$userId && $customerId) {
            $userId = getUserIdByCustomer($customerId) ?? 0;
        }

        if ($userId) {
            setUserPlan($userId, 'free');
            // Clear sub ID but keep customer ID for future resubscription
            try {
                getDB()->prepare('UPDATE users SET stripe_sub_id = NULL WHERE id = ?')
                       ->execute([$userId]);
            } catch (PDOException $e) {}
        }
        break;

    // ── Payment failed: log but keep plan active (Stripe retries for days) ────
    case 'invoice.payment_failed':
        $customerId = $object['customer'] ?? null;
        $email      = $object['customer_email'] ?? '';
        error_log("stripe-webhook: payment_failed customer=$customerId email=$email");
        // Stripe will retry; only downgrade when subscription.deleted fires
        break;

    default:
        // Ignore other events
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);
