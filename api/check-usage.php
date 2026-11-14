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
        'plan'                => 'guest',
        'ai_used'             => 0,
        'ai_limit'            => 0,
        'ai_remaining'        => 0,
        'enhance_used'        => $enhUsed,
        'enhance_limit'       => FREE_ENHANCE_DAILY,
        'enhance_remaining'   => max(0, FREE_ENHANCE_DAILY - $enhUsed),
        'transform_used'      => 0,
        'transform_limit'     => 0,
        'transform_remaining' => 0,
        'resets_at'           => $today . ' 23:59:59',
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

    // AI transform count
    $stmt3 = $db->prepare(
        "SELECT COUNT(*) FROM usage_log WHERE user_id = ? AND action = 'transform' AND DATE(created_at) = CURDATE()"
    );
    $stmt3->execute([$uid]);
    $txUsed = (int)$stmt3->fetchColumn();

    $txDailyLimit = defined('AI_TRANSFORM_DAILY') ? AI_TRANSFORM_DAILY : 5;

    if ($plan === 'free') {
        $freeCaptionLimit = defined('FREE_DAILY_LIMIT') ? FREE_DAILY_LIMIT : 3;
        $aiLimit    = $freeCaptionLimit;
        $aiRemain   = max(0, $freeCaptionLimit - $aiUsed);
        $enhLimit   = FREE_ENHANCE_DAILY;
        $enhRemain  = max(0, FREE_ENHANCE_DAILY - $enhUsed);
        $txLimit    = 0;              // AI transforms are premium-only
        $txRemain   = 0;
    } else {
        // Premium or teams
        $aiLimit    = PREMIUM_AI_DAILY;
        $aiRemain   = max(0, PREMIUM_AI_DAILY - $aiUsed);
        $enhLimit   = null;           // unlimited
        $enhRemain  = null;
        $txLimit    = $txDailyLimit;
        $txRemain   = max(0, $txDailyLimit - $txUsed);
    }

    json_out([
        'plan'               => $plan,
        'ai_used'            => $aiUsed,
        'ai_limit'           => $aiLimit,
        'ai_remaining'       => $aiRemain,
        'enhance_used'       => $enhUsed,
        'enhance_limit'      => $enhLimit,
        'enhance_remaining'  => $enhRemain,
        'transform_used'     => $txUsed,
        'transform_limit'    => $txLimit,
        'transform_remaining'=> $txRemain,
        'resets_at'          => date('Y-m-d') . ' 23:59:59',
    ]);

} catch (PDOException $e) {
    error_log('check-usage: ' . $e->getMessage());
    json_out([
        'plan'=>'free','ai_used'=>0,'ai_limit'=>0,'ai_remaining'=>0,
        'enhance_used'=>0,'enhance_limit'=>FREE_ENHANCE_DAILY,'enhance_remaining'=>FREE_ENHANCE_DAILY,
        'transform_used'=>0,'transform_limit'=>0,'transform_remaining'=>0,
        'resets_at'=>date('Y-m-d').' 23:59:59',
    ]);
}
