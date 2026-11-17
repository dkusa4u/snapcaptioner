<?php
/**
 * api/boost-caption.php
 * GPT-powered caption booster for Premium / Pro / Teams users.
 * Takes a weak caption + its 5-axis scores, returns an improved caption + new scores.
 */

require_once __DIR__ . '/config.php';    // DB_PASS, OPENAI_API_KEY, DB_* constants
require_once __DIR__ . '/db.php';        // getPDO(), authUser()

header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────────────────────────
$user = authUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$allowedPlans = ['premium', 'pro', 'teams'];
if (!in_array($user['plan'] ?? 'free', $allowedPlans)) {
    http_response_code(403);
    echo json_encode(['error' => 'Boost requires Premium, Pro, or Teams plan']);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$caption = trim($body['caption'] ?? '');
$scores  = $body['scores'] ?? [];
$idx     = (int)($body['idx'] ?? 0);

if (!$caption) {
    http_response_code(400);
    echo json_encode(['error' => 'Caption is required']);
    exit;
}

$axes = ['scroll_stop', 'aesthetic', 'authenticity', 'social_seo', 'engagement'];

// Build a readable weakness summary for the prompt
$weaknesses = [];
foreach ($axes as $ax) {
    $v = (int)($scores[$ax] ?? 70);
    if ($v < 70) {
        $label = ucwords(str_replace('_', ' ', $ax));
        $weaknesses[] = "$label ($v/100)";
    }
}
$weakStr = $weaknesses
    ? 'Specific weaknesses to address: ' . implode(', ', $weaknesses) . '.'
    : 'All axes are reasonable — push all of them higher.';

// ── GPT call ─────────────────────────────────────────────────────────────────
$systemPrompt = <<<SYSTEM
You are an elite social media caption strategist. Your job is to rewrite a caption to dramatically improve its performance on Instagram / TikTok / LinkedIn.

Rules:
- Keep the core message and voice intact — improve, don't replace.
- Make the opening line impossible to scroll past (pattern-break, bold claim, or curiosity gap).
- Add sensory or vivid language where appropriate.
- Keep it authentic — human, not AI-sounding.
- Naturally weave in searchable keywords and 2-4 hashtags at the end.
- End with a clear, natural call-to-action (question, save-prompt, or share nudge).
- Length: same ballpark as the original (±20%).
- Return ONLY valid JSON in this exact shape:
{"caption":"improved text here","scores":{"scroll_stop":88,"aesthetic":82,"authenticity":90,"social_seo":85,"engagement":87},"master_score":86}
SYSTEM;

$userPrompt = "Original caption:\n\"\"\"\n$caption\n\"\"\"\n\n$weakStr\n\nReturn the improved caption + new scores as JSON.";

$payload = [
    'model'       => 'gpt-4o-mini',
    'max_tokens'  => 800,
    'temperature' => 0.75,
    'messages'    => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt],
    ],
    'response_format' => ['type' => 'json_object'],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 30,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    http_response_code(502);
    echo json_encode(['error' => 'OpenAI request failed: ' . $err]);
    exit;
}

$oai = json_decode($raw, true);
if (!isset($oai['choices'][0]['message']['content'])) {
    http_response_code(502);
    echo json_encode(['error' => 'OpenAI returned unexpected response']);
    exit;
}

$result = json_decode($oai['choices'][0]['message']['content'], true);
if (!$result || empty($result['caption'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not parse boosted caption from GPT']);
    exit;
}

// Sanitise scores
$outScores = [];
foreach ($axes as $ax) {
    $outScores[$ax] = max(0, min(100, (int)($result['scores'][$ax] ?? 70)));
}
$masterScore = isset($result['master_score'])
    ? max(0, min(100, (int)$result['master_score']))
    : (int)round(array_sum($outScores) / 5);

// ── Log usage ─────────────────────────────────────────────────────────────────
try {
    $pdo = getPDO();
    $stmt = $pdo->prepare("INSERT INTO usage_log (user_id, action, meta, created_at)
                           VALUES (:uid, 'boost_caption', :meta, NOW())");
    $stmt->execute([
        ':uid'  => $user['id'],
        ':meta' => json_encode(['idx' => $idx, 'master_score' => $masterScore]),
    ]);
} catch (Exception $e) {
    // Non-fatal — don't block the response
}

echo json_encode([
    'caption'      => $result['caption'],
    'scores'       => $outScores,
    'master_score' => $masterScore,
]);
