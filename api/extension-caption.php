<?php
/**
 * SnapCaption AI — Chrome Extension Caption Generator
 * POST /api/extension-caption.php
 *
 * Body JSON:
 *   topic      string  required — what the post is about
 *   platform   string  optional (default: Instagram)
 *   tone       string  optional (default: Casual)
 *   count      int     optional 3–10 (default: 5)
 *   language   string  optional (default: English)
 *   image_url  string  optional — context image URL (right-click source)
 *
 * Pro-gated. Returns { captions: string[], usage_remaining: int|null }
 */

require_once __DIR__ . '/db.php';

// ── CORS for extension ────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

// ── Auth ──────────────────────────────────────────────────────────────────
$user = tokenAuth();   // exits with 401 on failure

// ── Pro gate ──────────────────────────────────────────────────────────────
if (!planIsPro($user['plan'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Chrome Extension requires a Pro plan.', 'upgrade' => true]);
    exit;
}

// ── Parse body ────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true) ?: [];

$topic     = trim($body['topic']     ?? '');
$platform  = trim($body['platform']  ?? 'Instagram');
$tone      = trim($body['tone']      ?? 'Casual');
$count     = max(3, min(10, (int)($body['count'] ?? 5)));
$language  = trim($body['language']  ?? 'English');
$imageUrl  = trim($body['image_url'] ?? '');

if (!$topic) {
    http_response_code(400);
    echo json_encode(['error' => 'topic is required']);
    exit;
}

// Sanitise inputs
$allowed_platforms = ['Instagram','TikTok','YouTube','Facebook','LinkedIn','X (Twitter)','Pinterest'];
$allowed_tones     = ['Casual','Professional','Funny','Inspirational','Educational','Promotional','Storytelling','Minimalist','Bold'];
if (!in_array($platform, $allowed_platforms)) $platform = 'Instagram';
if (!in_array($tone, $allowed_tones))         $tone     = 'Casual';
$language = preg_replace('/[^A-Za-z ()\-]/', '', $language);

// ── Usage check ───────────────────────────────────────────────────────────
$pdo     = getDb();
$today   = date('Y-m-d');
$userId  = $user['id'];

// unlimited for Pro
$usageRemaining = null;

// Log usage event
try {
    $pdo->prepare("INSERT INTO usage_log (user_id, action, platform, tone, language, created_at)
                   VALUES (?, 'extension_caption', ?, ?, ?, NOW())")
        ->execute([$userId, $platform, $tone, $language]);
} catch (Exception $e) { /* non-fatal */ }

// ── Build OpenAI prompt ───────────────────────────────────────────────────
$imageContext = '';
if ($imageUrl) {
    $imageContext = "\n\nThe user has selected an image from the page — use this context in your captions: image visible at $imageUrl";
}

$platformHints = [
    'Instagram'   => 'Up to 2200 chars. Use emojis naturally. Include 3–5 relevant hashtags at the end.',
    'TikTok'      => 'Short and punchy (under 300 chars). Start with a hook. 3–5 TikTok-style hashtags.',
    'YouTube'     => 'Write as a video description (1–2 sentences). Include a CTA like "Watch now" or "Link in bio".',
    'Facebook'    => 'Conversational, 1–3 sentences. No hashtag overload — 1–2 max.',
    'LinkedIn'    => 'Professional tone, 2–4 sentences. Add a question or CTA. 3–5 niche hashtags.',
    'X (Twitter)' => 'Under 280 characters total. Punchy and direct. 1–2 hashtags maximum.',
    'Pinterest'   => 'Descriptive and keyword-rich. 2–3 sentences. 2–4 descriptive hashtags.',
];

$hint = $platformHints[$platform] ?? $platformHints['Instagram'];

$systemPrompt = <<<PROMPT
You are a social media caption expert. Generate exactly {$count} unique captions.
Platform: {$platform}. {$hint}
Tone: {$tone}.
Language: {$language}.

Return ONLY valid JSON: { "captions": ["caption1", "caption2", ...] }
No commentary, no markdown, no extra keys.
PROMPT;

$userPrompt = "Post topic: {$topic}{$imageContext}";

// ── Call OpenAI ───────────────────────────────────────────────────────────
$payload = json_encode([
    'model'           => 'gpt-4o-mini',
    'response_format' => ['type' => 'json_object'],
    'messages'        => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt],
    ],
    'max_tokens'      => 1500,
    'temperature'     => 0.85,
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_KEY,
    ],
    CURLOPT_TIMEOUT        => 30,
]);

$raw      = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'OpenAI request failed: ' . $curlErr]);
    exit;
}

$aiResp = json_decode($raw, true);
if ($httpCode !== 200 || empty($aiResp['choices'][0]['message']['content'])) {
    http_response_code(502);
    echo json_encode(['error' => 'AI response error']);
    exit;
}

$content = json_decode($aiResp['choices'][0]['message']['content'], true);
$captions = $content['captions'] ?? [];

if (!is_array($captions) || empty($captions)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse captions from AI response']);
    exit;
}

// ── Store in caption_history ──────────────────────────────────────────────
try {
    $captionJson = json_encode($captions);
    $pdo->prepare("INSERT INTO caption_history (user_id, platform, tone, language, context, captions, created_at)
                   VALUES (?, ?, ?, ?, ?, ?, NOW())")
        ->execute([$userId, $platform, $tone, $language, $topic, $captionJson]);
} catch (Exception $e) { /* non-fatal */ }

// ── Respond ───────────────────────────────────────────────────────────────
echo json_encode([
    'ok'              => true,
    'captions'        => $captions,
    'usage_remaining' => $usageRemaining,
]);
