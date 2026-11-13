<?php
// track-enhance.php — Check and record a photo-enhance action
// Free users: 3 canvas edits/day  |  Premium: unlimited
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

// ── Bootstrap ──────────────────────────────────────────────────────────────
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) { http_response_code(500); echo json_encode(['error'=>'Server configuration missing']); exit; }
require_once $configPath;

session_start();
$today = date('Y-m-d');

$user      = tokenAuth();
$isPremium = $user && $user['plan'] !== 'free';

// ── Premium: always allowed, log and return ────────────────────────────────
if ($isPremium) {
    $db   = getDB();
    $stmt = $db->prepare(
        "INSERT INTO usage_log (user_id, action, created_at) VALUES (?, 'enhance', NOW())"
    );
    $stmt->execute([$user['id']]);

    // Count today for the response
    $countStmt = $db->prepare(
        "SELECT COUNT(*) FROM usage_log WHERE user_id = ? AND action = 'enhance' AND DATE(created_at) = CURDATE()"
    );
    $countStmt->execute([$user['id']]);
    $usedToday = (int) $countStmt->fetchColumn();

    echo json_encode([
        'allowed'    => true,
        'is_premium' => true,
        'used'       => $usedToday,
        'limit'      => null,       // unlimited
        'remaining'  => null,
    ]);
    exit;
}

// ── Free logged-in users ───────────────────────────────────────────────────
if ($user) {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM usage_log WHERE user_id = ? AND action = 'enhance' AND DATE(created_at) = CURDATE()"
    );
    $stmt->execute([$user['id']]);
    $usedToday = (int) $stmt->fetchColumn();

    if ($usedToday >= FREE_ENHANCE_DAILY) {
        echo json_encode([
            'allowed'     => false,
            'is_premium'  => false,
            'used'        => $usedToday,
            'limit'       => FREE_ENHANCE_DAILY,
            'remaining'   => 0,
            'upgrade_url' => '/pricing.html',
            'message'     => 'You\'ve used all ' . FREE_ENHANCE_DAILY . ' free photo edits for today. Upgrade to Premium for unlimited.',
        ]);
        exit;
    }

    // Log and return
    $ins = $db->prepare(
        "INSERT INTO usage_log (user_id, action, created_at) VALUES (?, 'enhance', NOW())"
    );
    $ins->execute([$user['id']]);
    $usedNow = $usedToday + 1;

    echo json_encode([
        'allowed'    => true,
        'is_premium' => false,
        'used'       => $usedNow,
        'limit'      => FREE_ENHANCE_DAILY,
        'remaining'  => max(0, FREE_ENHANCE_DAILY - $usedNow),
    ]);
    exit;
}

// ── Guest (not logged in): session-based tracking ─────────────────────────
if (!isset($_SESSION['enhance_date']) || $_SESSION['enhance_date'] !== $today) {
    $_SESSION['enhance_date']  = $today;
    $_SESSION['enhance_count'] = 0;
}

if ($_SESSION['enhance_count'] >= FREE_ENHANCE_DAILY) {
    echo json_encode([
        'allowed'     => false,
        'is_premium'  => false,
        'used'        => $_SESSION['enhance_count'],
        'limit'       => FREE_ENHANCE_DAILY,
        'remaining'   => 0,
        'upgrade_url' => '/pricing.html',
        'message'     => 'You\'ve used all ' . FREE_ENHANCE_DAILY . ' free photo edits for today. Sign in or upgrade to Premium for more.',
    ]);
    exit;
}

$_SESSION['enhance_count']++;

echo json_encode([
    'allowed'    => true,
    'is_premium' => false,
    'used'       => $_SESSION['enhance_count'],
    'limit'      => FREE_ENHANCE_DAILY,
    'remaining'  => max(0, FREE_ENHANCE_DAILY - $_SESSION['enhance_count']),
]);
exit;
