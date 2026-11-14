<?php
/**
 * GET /api/analytics.php
 * Returns analytics data for the authenticated user.
 *
 * Response:
 * {
 *   summary: { total_captions, total_days_active, current_streak, avg_hook_score, fav_count },
 *   by_day:  [ { date:"2026-11-01", count:3 }, … ]  // last 30 days
 *   by_platform: [ { platform:"Instagram", count:12 }, … ]
 *   by_tone:     [ { tone:"Witty", count:8 }, … ]
 *   by_language: [ { language:"English", count:15 }, … ]
 *   hook_trend:  [ { date:"2026-11-01", avg_hook:7.4 }, … ]  // last 30 days
 *   recent:      [ { platform, tone, language, caption_1, hook_scores, favorited, created_at }, … ] // last 10
 * }
 */

require_once __DIR__ . '/db.php';
cors();
$user = tokenAuth();
$uid  = (int)$user['id'];
$db   = getDb();

// ── Date boundaries ────────────────────────────────────────────────────────
$today    = date('Y-m-d');
$day30ago = date('Y-m-d', strtotime('-29 days'));

// ── Summary ───────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        COUNT(*) AS total_captions,
        COUNT(DISTINCT DATE(created_at)) AS total_days_active,
        SUM(favorited) AS fav_count
    FROM captions
    WHERE user_id = ?
");
$stmt->execute([$uid]);
$summaryRow = $stmt->fetch(PDO::FETCH_ASSOC);

// Average hook score — parse comma-separated hook_scores column
$stmt = $db->prepare("
    SELECT hook_scores FROM captions WHERE user_id = ? AND hook_scores IS NOT NULL AND hook_scores != ''
");
$stmt->execute([$uid]);
$hookRows = $stmt->fetchAll(PDO::FETCH_COLUMN);
$allScores = [];
foreach ($hookRows as $hs) {
    foreach (explode(',', $hs) as $v) {
        $v = trim($v);
        if (is_numeric($v)) $allScores[] = (float)$v;
    }
}
$avgHook = count($allScores) ? round(array_sum($allScores) / count($allScores), 1) : null;

// Current streak (consecutive days with at least one caption)
$stmt = $db->prepare("
    SELECT DISTINCT DATE(created_at) AS d
    FROM captions
    WHERE user_id = ?
    ORDER BY d DESC
    LIMIT 60
");
$stmt->execute([$uid]);
$days = $stmt->fetchAll(PDO::FETCH_COLUMN);

$streak = 0;
$check  = $today;
foreach ($days as $d) {
    if ($d === $check) {
        $streak++;
        $check = date('Y-m-d', strtotime($d . ' -1 day'));
    } elseif ($d < $check) {
        // Gap — if the gap is only "today is missing", start from yesterday
        if ($streak === 0 && $d === date('Y-m-d', strtotime('-1 day'))) {
            $streak++;
            $check = date('Y-m-d', strtotime($d . ' -1 day'));
        } else {
            break;
        }
    }
}

$summary = [
    'total_captions'   => (int)($summaryRow['total_captions'] ?? 0),
    'total_days_active'=> (int)($summaryRow['total_days_active'] ?? 0),
    'current_streak'   => $streak,
    'avg_hook_score'   => $avgHook,
    'fav_count'        => (int)($summaryRow['fav_count'] ?? 0),
];

// ── By day (last 30 days) ─────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT DATE(created_at) AS date, COUNT(*) AS count
    FROM captions
    WHERE user_id = ? AND DATE(created_at) >= ?
    GROUP BY date
    ORDER BY date ASC
");
$stmt->execute([$uid, $day30ago]);
$byDayRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fill in zeros for missing days
$byDayMap = [];
foreach ($byDayRaw as $r) $byDayMap[$r['date']] = (int)$r['count'];
$byDay = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $byDay[] = ['date' => $d, 'count' => $byDayMap[$d] ?? 0];
}

// ── By platform ───────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT platform, COUNT(*) AS count
    FROM captions
    WHERE user_id = ?
    GROUP BY platform
    ORDER BY count DESC
    LIMIT 8
");
$stmt->execute([$uid]);
$byPlatform = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($byPlatform as &$r) $r['count'] = (int)$r['count'];
unset($r);

// ── By tone ───────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT tone, COUNT(*) AS count
    FROM captions
    WHERE user_id = ? AND tone IS NOT NULL AND tone != ''
    GROUP BY tone
    ORDER BY count DESC
    LIMIT 6
");
$stmt->execute([$uid]);
$byTone = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($byTone as &$r) $r['count'] = (int)$r['count'];
unset($r);

// ── By language ───────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT language, COUNT(*) AS count
    FROM captions
    WHERE user_id = ? AND language IS NOT NULL AND language != ''
    GROUP BY language
    ORDER BY count DESC
    LIMIT 6
");
$stmt->execute([$uid]);
$byLanguage = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($byLanguage as &$r) $r['count'] = (int)$r['count'];
unset($r);

// ── Hook score trend (avg per day, last 30 days) ──────────────────────────
$stmt = $db->prepare("
    SELECT DATE(created_at) AS date, hook_scores
    FROM captions
    WHERE user_id = ? AND DATE(created_at) >= ? AND hook_scores IS NOT NULL AND hook_scores != ''
    ORDER BY date ASC
");
$stmt->execute([$uid, $day30ago]);
$hookRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hookByDay = [];
foreach ($hookRows as $row) {
    $d = $row['date'];
    if (!isset($hookByDay[$d])) $hookByDay[$d] = [];
    foreach (explode(',', $row['hook_scores']) as $v) {
        $v = trim($v);
        if (is_numeric($v)) $hookByDay[$d][] = (float)$v;
    }
}
$hookTrend = [];
foreach ($byDay as $dayItem) {
    $d = $dayItem['date'];
    if (isset($hookByDay[$d]) && count($hookByDay[$d])) {
        $avg = array_sum($hookByDay[$d]) / count($hookByDay[$d]);
        $hookTrend[] = ['date' => $d, 'avg_hook' => round($avg, 1)];
    }
}

// ── Recent captions (last 10) ─────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT platform, tone, language, caption_1, hook_scores, favorited,
           DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at
    FROM captions
    WHERE user_id = ?
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->execute([$uid]);
$recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($recent as &$r) {
    $r['favorited'] = (bool)$r['favorited'];
    // Truncate caption for display
    if (strlen($r['caption_1']) > 120) $r['caption_1'] = substr($r['caption_1'], 0, 117) . '…';
}
unset($r);

json_out([
    'success'     => true,
    'summary'     => $summary,
    'by_day'      => $byDay,
    'by_platform' => $byPlatform,
    'by_tone'     => $byTone,
    'by_language' => $byLanguage,
    'hook_trend'  => $hookTrend,
    'recent'      => $recent,
]);
