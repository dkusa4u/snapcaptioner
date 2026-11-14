<?php
/**
 * SnapCaption AI — POST /api/bulk-generate.php
 * Pro-plan bulk caption generation — processes one image per request.
 *
 * Client calls this endpoint once per image in sequence.
 *
 * Request body (JSON):
 *   {
 *     "image":    "<base64 string>",
 *     "platform": "Instagram",
 *     "language": "English",
 *     "tone":     "engaging",
 *     "context":  "optional extra context",
 *     "job_id":   "client-generated UUID for the batch"
 *   }
 *
 * Response (JSON):
 *   {
 *     success: true,
 *     caption: "best caption text",
 *     hashtags: ["tag1","tag2",...],
 *     all_captions: ["cap1","cap2",...],
 *     alt_text: "...",
 *     engagement_tip: "..."
 *   }
 */

require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method_not_allowed'], 405);
}

// ── Load config ───────────────────────────────────────────
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    json_out(['error' => 'Server not configured. Add api/config.php.'], 500);
}
require_once $configPath;

// ── Auth: Pro plan only ───────────────────────────────────
$user = tokenAuth();
if (!$user) {
    json_out(['error' => 'login_required', 'message' => 'Sign in to use Bulk Generate.'], 401);
}

$plan = $user['plan'] ?? 'free';
if (!planIsPro($plan)) {
    json_out([
        'error'       => 'pro_required',
        'message'     => 'Bulk caption generation is a Pro plan feature.',
        'upgrade_url' => '/pricing.html',
    ], 403);
}

// ── Rate limit: Pro = unlimited captions ──────────────────
// planCaptionLimit('pro') = null (unlimited), so no daily cap applies.
// Guard is here in case plan changes; currently a no-op for Pro.

// ── Parse input ───────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$imageBase64 = trim($input['image']    ?? '');
$platform    = trim($input['platform'] ?? 'Instagram');
$language    = trim($input['language'] ?? 'English');
$tone        = trim($input['tone']     ?? 'engaging');
$context     = trim($input['context']  ?? '');
$jobId       = preg_replace('/[^a-zA-Z0-9\-_]/', '', $input['job_id'] ?? '');

if (strlen($imageBase64) < 100) {
    json_out(['error' => 'invalid_image', 'message' => 'Invalid or missing image data.'], 400);
}

// ── Detect image type ─────────────────────────────────────
$imageType = 'jpeg';
$decoded = base64_decode(substr($imageBase64, 0, 20));
if (substr($decoded, 0, 4) === "\x89PNG") $imageType = 'png';
elseif (substr($decoded, 1, 3) === 'PNG') $imageType = 'png';
elseif (substr($decoded, 0, 4) === 'RIFF') $imageType = 'webp';

// ── Prompts (same as generate-captions.php) ───────────────
$toneDescriptions = [
    'engaging'      => 'engaging, conversational, and relatable — feels like a real person talking',
    'funny'         => 'witty, humorous, and playful — uses wordplay, light sarcasm, or clever observations',
    'inspirational' => 'uplifting, motivational, and empowering — inspires the reader to feel or do something',
    'professional'  => 'polished, authoritative, and professional — suitable for business or career content',
    'storytelling'  => 'narrative and personal — tells a mini-story or shares a feeling from the moment',
    'minimal'       => 'short, clean, and minimal — just the essence, no filler words',
];
$toneDesc = $toneDescriptions[$tone] ?? $toneDescriptions['engaging'];

$platformGuidelines = [
    'Instagram' => 'Instagram: up to 2200 chars but 125 is ideal, emojis encouraged, 3–5 hashtags in caption (rest in comment), hook in first line is critical, conversational',
    'TikTok'    => 'TikTok: very short and punchy (under 150 chars ideal), trendy language, challenge-style CTAs work well, 2–3 hashtags max, GenZ tone acceptable',
    'LinkedIn'  => 'LinkedIn: professional but human, 150–300 chars ideal, thought leadership angle, avoid heavy slang, 3–5 hashtags at end, story format performs well',
    'X'         => 'X (Twitter): max 280 chars strict, punchy and direct, witty hooks, 1–2 hashtags max, question endings boost engagement',
    'Facebook'  => 'Facebook: 40–80 chars for best reach, friendly and community-focused, questions drive comments, minimal hashtags (1–2)',
    'Pinterest' => 'Pinterest: descriptive and keyword-rich (200-300 chars), how-to and inspirational angles, 2–5 niche hashtags',
];
$platGuide   = $platformGuidelines[$platform] ?? $platformGuidelines['Instagram'];
$contextNote = $context ? "\nExtra context: \"$context\"" : '';

// ── Fetch brand voice if saved ────────────────────────────
$brandVoiceNote = '';
if (!empty($user['brand_voice'])) {
    $brandVoiceNote = "\n\nBrand Voice (follow this closely):\n" . $user['brand_voice'];
}

$systemPrompt = "You are SnapCaption AI, an expert social media copywriter who creates platform-optimized captions from photos. You write natively in any language — not translations.";

$userPrompt = <<<PROMPT
Analyze this photo and generate social media content for $platform in $language.

Tone: $toneDesc
Platform guidelines: $platGuide$contextNote$brandVoiceNote

Return ONLY valid JSON (no markdown, no explanation) in exactly this format:
{
  "captions": [
    {"text": "caption text here", "hook_score": 9},
    {"text": "caption text here", "hook_score": 8},
    {"text": "caption text here", "hook_score": 7}
  ],
  "hashtags": ["tag1","tag2","tag3","tag4","tag5","tag6","tag7","tag8","tag9","tag10"],
  "alt_text": "Descriptive alt text for accessibility",
  "engagement_tip": "One sentence tip to boost engagement for this specific photo"
}

Rules:
- Write ALL captions AND alt_text natively in $language
- hook_score is 1-10 (10 = irresistible scroll-stopper)
- Provide EXACTLY 10 hashtags (lowercase, no spaces, no # symbol)
- Captions must be meaningfully different from each other
PROMPT;

// ── Call OpenAI ───────────────────────────────────────────
$payload = [
    'model'       => 'gpt-4o-mini',
    'max_tokens'  => 900,
    'temperature' => 0.8,
    'messages'    => [
        ['role' => 'system', 'content' => $systemPrompt],
        [
            'role'    => 'user',
            'content' => [
                [
                    'type'      => 'image_url',
                    'image_url' => ['url' => "data:image/$imageType;base64,$imageBase64", 'detail' => 'low']
                ],
                ['type' => 'text', 'text' => $userPrompt]
            ]
        ]
    ]
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$rawResponse = curl_exec($ch);
$curlError   = curl_error($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    json_out(['error' => 'network_error', 'message' => 'Network error: ' . $curlError], 500);
}
if ($httpCode !== 200) {
    $errBody = json_decode($rawResponse, true);
    $errMsg  = $errBody['error']['message'] ?? "OpenAI HTTP $httpCode";
    json_out(['error' => 'ai_error', 'message' => $errMsg], 500);
}

// ── Parse response ────────────────────────────────────────
$openAiResponse = json_decode($rawResponse, true);
$aiText = $openAiResponse['choices'][0]['message']['content'] ?? '';
$aiText = preg_replace('/^```json\s*/i', '', trim($aiText));
$aiText = preg_replace('/\s*```$/i', '', $aiText);
$aiText = trim($aiText);

$result = json_decode($aiText, true);
if (!$result) {
    if (preg_match('/\{[\s\S]+\}/m', $aiText, $m)) {
        $result = json_decode($m[0], true);
    }
}

if (!$result || empty($result['captions'])) {
    json_out(['error' => 'parse_error', 'message' => 'Could not parse AI response.'], 500);
}

// Pick best caption (highest hook_score)
usort($result['captions'], fn($a, $b) => ($b['hook_score'] ?? 0) <=> ($a['hook_score'] ?? 0));
$bestCaption = $result['captions'][0]['text'] ?? '';
$allCaptions = array_column($result['captions'], 'text');

// Normalize hashtags
$hashtags = $result['hashtags'] ?? [];
if (!empty($hashtags) && is_array($hashtags[0])) {
    // Object format fallback
    $hashtags = array_column($hashtags, 'tag');
}
$hashtags = array_slice($hashtags, 0, 10);

// ── Log usage ─────────────────────────────────────────────
try {
    $db   = getDB();
    // Migrate usage_log to add bulk-related columns if missing
    try {
        $db->exec("ALTER TABLE usage_log ADD COLUMN platform VARCHAR(30) NULL");
    } catch (PDOException $e) {}
    try {
        $db->exec("ALTER TABLE usage_log ADD COLUMN job_id VARCHAR(64) NULL");
    } catch (PDOException $e) {}

    $stmt = $db->prepare(
        "INSERT INTO usage_log (user_id, action, platform, job_id, created_at)
         VALUES (?, 'generate', ?, ?, NOW())"
    );
    $stmt->execute([$user['id'], $platform, $jobId ?: null]);
} catch (PDOException $e) {
    error_log('bulk-generate usage_log: ' . $e->getMessage());
}

json_out([
    'success'        => true,
    'caption'        => $bestCaption,
    'all_captions'   => $allCaptions,
    'hashtags'       => $hashtags,
    'alt_text'       => $result['alt_text']       ?? '',
    'engagement_tip' => $result['engagement_tip'] ?? '',
]);
