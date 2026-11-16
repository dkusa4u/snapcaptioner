<?php
/**
 * SnapCaption AI — /api/video-script.php
 *
 * POST {
 *   topic:    string (required)
 *   niche:    string  (lifestyle|fitness|business|beauty|food|travel|education|tech|motivation|entertainment)
 *   style:    string  (talking-head|voiceover|text-on-screen|storytelling|listicle|tutorial|trending-audio)
 *   platform: string  (TikTok|Instagram Reels|YouTube Shorts|Facebook Reels)
 *   duration: int     (15|30|60|90)
 *   hook:     string  optional custom hook idea
 * }
 * → {
 *     success, platform, duration,
 *     script_sections: [{ name, emoji, timing, content }],
 *     caption,
 *   }
 */

require_once __DIR__ . '/db.php';
cors();

$user = tokenAuth();
if (!$user) json_out(['success' => false, 'message' => 'Unauthorized'], 401);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$topic    = trim($body['topic'] ?? '');
$niche    = trim($body['niche'] ?? 'lifestyle');
$style    = trim($body['style'] ?? 'talking-head');
$platform = trim($body['platform'] ?? 'TikTok');
$duration = (int)($body['duration'] ?? 30);
$hook     = trim($body['hook'] ?? '');

if (!$topic) json_out(['success' => false, 'message' => 'Topic is required'], 400);

$validDurations = [15, 30, 60, 90];
if (!in_array($duration, $validDurations)) $duration = 30;

$validNiches  = ['lifestyle','fitness','business','beauty','food','travel','education','tech','motivation','entertainment'];
$validStyles  = ['talking-head','voiceover','text-on-screen','storytelling','listicle','tutorial','trending-audio'];
$validPlats   = ['TikTok','Instagram Reels','YouTube Shorts','Facebook Reels'];
if (!in_array($niche,    $validNiches))  $niche    = 'lifestyle';
if (!in_array($style,    $validStyles))  $style    = 'talking-head';
if (!in_array($platform, $validPlats))   $platform = 'TikTok';

// ── Build timing plan based on duration ──────────────────────────────────
$timingMap = [
    15 => [
        ['name' => 'Hook',    'emoji' => '⚡', 'sec' => '0-3s',  'words' => '~10 words'],
        ['name' => 'Value',   'emoji' => '💡', 'sec' => '3-11s', 'words' => '~25 words'],
        ['name' => 'CTA',     'emoji' => '📲', 'sec' => '11-15s','words' => '~12 words'],
    ],
    30 => [
        ['name' => 'Hook',    'emoji' => '⚡', 'sec' => '0-3s',  'words' => '~10 words'],
        ['name' => 'Problem', 'emoji' => '😤', 'sec' => '3-8s',  'words' => '~20 words'],
        ['name' => 'Value',   'emoji' => '💡', 'sec' => '8-22s', 'words' => '~45 words'],
        ['name' => 'CTA',     'emoji' => '📲', 'sec' => '22-30s','words' => '~20 words'],
    ],
    60 => [
        ['name' => 'Hook',      'emoji' => '⚡', 'sec' => '0-4s',  'words' => '~12 words'],
        ['name' => 'Problem',   'emoji' => '😤', 'sec' => '4-12s', 'words' => '~30 words'],
        ['name' => 'Solution',  'emoji' => '✅', 'sec' => '12-40s','words' => '~85 words'],
        ['name' => 'Authority', 'emoji' => '🏆', 'sec' => '40-52s','words' => '~35 words'],
        ['name' => 'CTA',       'emoji' => '📲', 'sec' => '52-60s','words' => '~20 words'],
    ],
    90 => [
        ['name' => 'Hook',        'emoji' => '⚡', 'sec' => '0-5s',   'words' => '~15 words'],
        ['name' => 'Setup',       'emoji' => '🎯', 'sec' => '5-15s',  'words' => '~35 words'],
        ['name' => 'Point 1',     'emoji' => '1️⃣', 'sec' => '15-35s', 'words' => '~55 words'],
        ['name' => 'Point 2',     'emoji' => '2️⃣', 'sec' => '35-55s', 'words' => '~55 words'],
        ['name' => 'Point 3',     'emoji' => '3️⃣', 'sec' => '55-75s', 'words' => '~55 words'],
        ['name' => 'CTA',         'emoji' => '📲', 'sec' => '75-90s', 'words' => '~30 words'],
    ],
];

$sections   = $timingMap[$duration];
$hookLine   = $hook ? "\nCreator's hook idea: \"{$hook}\"" : '';
$styleHints = [
    'talking-head'   => 'Direct to camera, conversational, use "you" and "I", personal energy.',
    'voiceover'      => 'Narration style, describe visuals clearly, no filler words.',
    'text-on-screen' => 'Very short punchy lines for text overlay. Max 7 words per line. No "um" or fillers.',
    'storytelling'   => 'First-person narrative arc. Build tension. Use sensory details.',
    'listicle'       => 'Numbered points, rapid-fire tips. Clear transitions between each.',
    'tutorial'       => 'Step-by-step instructions. Clear action verbs. "First… Then… Finally…"',
    'trending-audio' => 'Script syncs to popular audio trend. Leave natural pause points.',
];
$styleHint = $styleHints[$style] ?? '';

$sectionDesc = json_encode(array_map(fn($s) => [
    'name'   => $s['name'],
    'emoji'  => $s['emoji'],
    'timing' => $s['sec'] . ' (' . $s['words'] . ')',
], $sections));

$systemPrompt = <<<PROMPT
You are a viral video script writer who has created 100M+ view TikTok/Reels scripts.
You write platform-native scripts that hold attention to the last second.
Return ONLY valid JSON — no markdown, no extra text.
PROMPT;

$userPrompt = <<<PROMPT
Write a {$duration}-second {$platform} script.
Topic: {$topic}
Niche: {$niche}
Style: {$style} — {$styleHint}
{$hookLine}

Script sections to fill in (follow timing exactly):
{$sectionDesc}

Rules:
- Hook MUST stop the scroll in 1-2 sentences. Use pattern interrupts, bold claims, or curiosity gaps.
- Every section ends on a word that makes them want to watch the next.
- {$style} tone throughout.
- Include natural pause markers [PAUSE], emphasis markers [LOUD], or visual cues [SHOW: ...] where relevant.
- CTA is specific: follow, comment with X, or save for later — not generic.

Also write:
- caption: a full {$platform} caption with hook line, 3-4 sentences, relevant hashtags, and best-time-to-post suggestion.

Return JSON exactly:
{
  "script_sections": [
    { "name": "Hook", "emoji": "⚡", "timing": "0-3s (~10 words)", "content": "..." },
    ...all sections...
  ],
  "caption": "...",
  "hook_score": 85
}
PROMPT;

// ── OpenAI call ───────────────────────────────────────────────────────────
$apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY') ?: '');
if (!$apiKey) json_out(['success' => false, 'message' => 'OpenAI API not configured'], 500);

$payload = json_encode([
    'model'       => 'gpt-4o-mini',
    'max_tokens'  => 1800,
    'temperature' => 0.82,
    'response_format' => ['type' => 'json_object'],
    'messages'    => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt],
    ],
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 40,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$raw || $code !== 200) {
    error_log("video-script OpenAI error $code: $raw");
    json_out(['success' => false, 'message' => 'Script generation failed. Please try again.'], 502);
}

$resp    = json_decode($raw, true);
$jsonStr = $resp['choices'][0]['message']['content'] ?? '';
$result  = json_decode($jsonStr, true);

if (!$result || empty($result['script_sections'])) {
    error_log("video-script parse error: $jsonStr");
    json_out(['success' => false, 'message' => 'Could not parse script. Please try again.'], 500);
}

json_out([
    'success'         => true,
    'platform'        => $platform,
    'duration'        => $duration,
    'script_sections' => $result['script_sections'],
    'caption'         => $result['caption'] ?? '',
    'hook_score'      => (int)($result['hook_score'] ?? 0),
]);
