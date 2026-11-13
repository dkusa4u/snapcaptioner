<?php
/**
 * SnapCaption AI — GET /api/check-usage.php
 * Returns current-day generation count and limit for the authenticated user.
 * Also cleans tokens older than their expiry.
 *
 * Response (JSON):
 *   { used, limit, remaining, plan, resets_at }
 */

require_once __DIR__ . '/db.php';
cors();

$user = tokenAuth();

if (!$user) {
    // Unauthenticated — return session-based count (kept in PHP session for guests)
    session_start();
    $today = date('Y-m-d');
    if (($session['usage_date'] ?? '') !== $today) {
        $_SESSION['usage_count'] = 0;
        $_SESSION['usage_date']  = $today;
    }
    $used  = (int)($_SESSION['usage_count'] ?? 0);
    $limit = FREE_DAILY_LIMIT;
    json_out([
        'used'      => $used,
        'limit'     => $limit,
        'remaining' => max(0, $limit - $used),
        'plan'      => 'guest',
        'resets_at' => $today . ' 23:59:59',
    ]);
}

// Authenticated user
try {
    $db  = getDB();
    $plan = $user['plan'];

    // Daily generation count from usage_log
    $stmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM usage_log
        WHERE user_id = ? AND action = 'generate'
          AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$user['id']]);
    $used  = (int)$stmt->fetchColumn();

    $limit     = ($plan === 'free') ? FREE_DAILY_LIMIT : PHP_INT_MAX;
    $remaining = ($plan === 'free') ? max(0, $limit - $used) : 999;

    json_out([
        'used'      => $used,
        'limit'     => ($plan === 'free') ? $limit : null,
        'remaining' => $remaining,
        'plan'      => $plan,
        'resets_at' => date('Y-m-d') . ' 23:59:59',
    ]);

} catch (PDOException $e) {
    error_log('check-usage: ' . $e->getMessage());
    json_out(['used'=>0,'limit'=>3,'remaining'=>3,'plan'=>'free','resets_at'=>date('Y-m-d').' 23:59:59']);
}
