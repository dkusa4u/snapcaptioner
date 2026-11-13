<?php
/**
 * SnapCaption AI — GET /api/check-usage.php
 * Returns current-day usage for both AI captions and photo enhances.
 *
 * Response (JSON):
 *   {
 *     plan,
 *     ai_used, ai_limit, ai_remaining,
 *     enhance_used, enhance_limit, enhance_remaining,
 *     resets_at
 *   }
 */

require_once __DIR__ . '/db.php';
cors();

$user = tokenAuth();

if (!$user) {
    // Guest — session-based tracking only
    session_start();
    $today = date('Y-m-d');
    if (($_SESSION['enhance_date'] ?? '') !== $today) {
        $_SESSION['enhance_count'] = 0;
        $_SESSION['enhance_date']  = $today;
    }
    $enhUsed = (int)($_SESSION['enhance_count'] ?? 0);
    json_out([
        'plan'             => 'guest',
        'ai_used'          => 0,
        'ai_limit'         => 0,
        'ai_remaining'     => 0,
        'enhance_used'     => $enhUsed,
        'enhance_limit'    => FREE_ENHANCE_DAILY,
        'enhance_remaining'=> max(0, FREE_ENHANCE_DAILY - $enhUsed),
        'resets_at'        => $today . ' 23:59:59',
    ]);
}

// Authenticated user
try {
    $db   = getDB();
    $plan = $user['plan'];
    $uid  = $user['id'];

    // AI caption count
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM usage_log WHERE user_id = ? AND action = 'generate' AND DATE(created_at) = CURDATE()"
    );
    $stmt->execute([$uid]);
    $aiUsed = (int)$stmt->fetchColumn();

    // Enhance count
    $stmt2 = $db->prepare(
        "SELECT COUNT(*) FROM usage_log WHERE user_id = ? AND action = 'enhance' AND DATE(created_at) = CURDATE()"
    );
    $stmt2->execute([$uid]);
    $enhUsed = (int)$stmt2->fetchColumn();

    if ($plan === 'free') {
        $aiLimit    = 0;              // Free users cannot use AI captions
        $aiRemain   = 0;
        $enhLimit   = FREE_ENHANCE_DAILY;
        $enhRemain  = max(0, FREE_ENHANCE_DAILY - $enhUsed);
    } else {
        // Premium or teams
        $aiLimit    = PREMIUM_AI_DAILY;
        $aiRemain   = max(0, PREMIUM_AI_DAILY - $aiUsed);
        $enhLimit   = null;           // unlimited
        $enhRemain  = null;
    }

    json_out([
        'plan'              => $plan,
        'ai_used'           => $aiUsed,
        'ai_limit'          => $aiLimit,
        'ai_remaining'      => $aiRemain,
        'enhance_used'      => $enhUsed,
        'enhance_limit'     => $enhLimit,
        'enhance_remaining' => $enhRemain,
        'resets_at'         => date('Y-m-d') . ' 23:59:59',
    ]);

} catch (PDOException $e) {
    error_log('check-usage: ' . $e->getMessage());
    json_out([
        'plan'=>'free','ai_used'=>0,'ai_limit'=>0,'ai_remaining'=>0,
        'enhance_used'=>0,'enhance_limit'=>FREE_ENHANCE_DAILY,'enhance_remaining'=>FREE_ENHANCE_DAILY,
        'resets_at'=>date('Y-m-d').' 23:59:59',
    ]);
}
