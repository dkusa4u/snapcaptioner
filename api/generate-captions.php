<?php
/**
 * SnapCaption AI — Caption Generator Backend
 * File: api/generate-captions.php
 *
 * Accepts: POST JSON { image, platform, language, tone, context }
 * Returns: JSON { captions[], hashtags[], best_time, best_time_note, alt_text, engagement_tip }
 *
 * Requires: OpenAI API key in config.php (see below)
 * Model: gpt-4o-mini (vision-capable)
 *
 * Setup on Hostinger:
 *   1. Create /api/config.php with your OpenAI API key (see template below)
 *   2. Upload this file to /api/generate-captions.php
 *   3. Make sure /api/ folder has PHP execution enabled
 */

// ── Load DB layer & handle CORS ────────────────────────────────────────────
require_once __DIR__ . '/db.php';
cors();

// ── Only allow POST ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Load config ────────────────────────────────────────────────────────────
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server not configured. Add api/config.php with OPENAI_API_KEY.']);
    exit;
}
require_once $configPath;

// ── Auth & rate limiting ───────────────────────────────────────────────────
session_start();
$today = date('Y-m-d');

$user = tokenAuth();   // null = unauthenticated guest

// Block guests (must be signed in to use AI captions)
if (!$user) {
    http_response_code(403);
    echo json_encode([
        'error'       => 'login_required',
        'message'     => 'Sign in to generate AI captions.',
        'login_url'   => '/login.html',
    ]);
    exit;
}

$plan      = $user['plan'] ?? 'free';
$isPremium = planIsPaid($plan);               // any paid plan
$dailyLimit = planCaptionLimit($plan);        // null = unlimited

// Count today's AI caption usage
$db   = getDB();
$stmt = $db->prepare(
    "SELECT COUNT(*) FROM usage_log
      WHERE user_id = ? AND action = 'generate' AND DATE(created_at) = CURDATE()"
);
$stmt->execute([$user['id']]);
$usedToday = (int) $stmt->fetchColumn();

// Enforce daily limit when not unlimited
if ($dailyLimit !== null && $usedToday >= $dailyLimit) {
    $upgradeMsg = $plan === 'free'
        ? 'Upgrade to Starter ($4.99/mo) for 15 captions/day, or Premium for unlimited.'
        : 'You\'ve used all ' . $dailyLimit . ' captions for today. Upgrade to Premium for unlimited. Resets at midnight.';
    http_response_code(429);
    echo json_encode([
        'error'       => 'daily_limit_reached',
        'message'     => 'You\'ve used all ' . $dailyLimit . ' AI captions for today. ' . $upgradeMsg,
        'used'        => $usedToday,
        'limit'       => $dailyLimit,
        'resets_at'   => date('Y-m-d', strtotime('+1 day')) . ' 00:00:00',
    ]);
    exit;
}

// ── Parse request body ─────────────────────────────────────────────────────
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input || !isset($input['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing image data']);
    exit;
}

$imageBase64 = $input['image'];
$platform    = isset($input['platform'])  ? trim($input['platform'])  : 'Instagram';
$language    = isset($input['language'])  ? trim($input['language'])  : 'English';
$tone        = isset($input['tone'])      ? trim($input['tone'])      : 'engaging';
$context     = isset($input['context'])   ? trim($input['context'])   : '';
$brandVoice  = isset($input['brand_voice']) ? substr(trim($input['brand_voice']), 0, 800) : '';

// Validate base64 image (basic sanity check)
if (strlen($imageBase64) < 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image data']);
    exit;
}

// Detect image type from base64 header or default to jpeg
$imageType = 'jpeg';
$decoded = base64_decode(substr($imageBase64, 0, 20));
if (substr($decoded, 0, 4) === "\x89PNG") $imageType = 'png';
elseif (substr($decoded, 1, 3) === 'PNG') $imageType = 'png';
elseif (substr($decoded, 0, 4) === 'RIFF') $imageType = 'webp';

// ── Build prompt ───────────────────────────────────────────────────────────
$toneDescriptions = [
    'engaging'       => 'engaging, conversational, and relatable — feels like a real person talking',
    'funny'          => 'witty, humorous, and playful — uses wordplay, light sarcasm, or clever observations',
    'inspirational'  => 'uplifting, motivational, and empowering — inspires the reader to feel or do something',
    'professional'   => 'polished, authoritative, and professional — suitable for business or career content',
    'storytelling'   => 'narrative and personal — tells a mini-story or shares a feeling from the moment',
    'minimal'        => 'short, clean, and minimal — just the essence, no filler words',
];
$toneDesc = $toneDescriptions[$tone] ?? $toneDescriptions['engaging'];

$platformGuidelines = [
    'Instagram' => 'Instagram: up to 2200 chars but 125 is ideal, emojis encouraged, 3–5 hashtags in caption (rest in comment), hook in first line is critical, conversational',
    'TikTok'    => 'TikTok: very short and punchy (under 150 chars ideal), trendy language, challenge-style CTAs work well, 2–3 hashtags max, GenZ tone acceptable',
    'LinkedIn'  => 'LinkedIn: professional but human, 150–300 chars ideal, thought leadership angle, avoid heavy slang, 3–5 hashtags at end, story format performs well',
    'X'         => 'X (Twitter): max 280 chars strict, punchy and direct, witty hooks, 1–2 hashtags max, question endings boost engagement, threads-friendly openers',
    'Facebook'  => 'Facebook: 40–80 chars for best reach, friendly and community-focused, questions drive comments, minimal hashtags (1–2), share-worthy angle',
    'Pinterest' => 'Pinterest: descriptive and keyword-rich (200-300 chars), how-to and inspirational angles, includes what viewers can do with it, 2–5 niche hashtags',
];
$platGuide = $platformGuidelines[$platform] ?? $platformGuidelines['Instagram'];

$contextNote    = $context    ? "\nExtra context from the user: \"$context\""    : '';
$brandVoiceNote = $brandVoice ? "\n\nIMPORTANT — Brand Voice DNA: The user has a saved brand voice profile. Match this voice and style closely in all 5 captions:\n\"$brandVoice\"" : '';

$systemPrompt = "You are SnapCaption AI, an expert social media copywriter who creates platform-optimized captions from photos. You write natively in any language — not translations.";

$userPrompt = <<<PROMPT
Analyze this photo and generate social media content for $platform in $language.

Tone: $toneDesc
Platform guidelines: $platGuide$contextNote$brandVoiceNote

Return ONLY valid JSON (no markdown, no explanation) in exactly this format:
{
  "captions": [
    {"text": "caption text here", "hook_score": 9, "scores": {"scroll_stop": 85, "aesthetic": 72, "authenticity": 88, "social_seo": 76, "engagement": 90}, "master_score": 82},
    {"text": "caption text here", "hook_score": 8, "scores": {"scroll_stop": 70, "aesthetic": 68, "authenticity": 75, "social_seo": 72, "engagement": 80}, "master_score": 73},
    {"text": "caption text here", "hook_score": 8, "scores": {"scroll_stop": 65, "aesthetic": 80, "authenticity": 70, "social_seo": 68, "engagement": 74}, "master_score": 71},
    {"text": "caption text here", "hook_score": 7, "scores": {"scroll_stop": 60, "aesthetic": 65, "authenticity": 72, "social_seo": 70, "engagement": 68}, "master_score": 67},
    {"text": "caption text here", "hook_score": 7, "scores": {"scroll_stop": 58, "aesthetic": 60, "authenticity": 65, "social_seo": 62, "engagement": 60}, "master_score": 61}
  ],
  "hashtags": [
    {"tag": "hashtagword", "tier": "sweet"},
    {"tag": "hashtagword", "tier": "sweet"},
    {"tag": "hashtagword", "tier": "comp"},
    {"tag": "hashtagword", "tier": "sat"}
  ],
  "best_time": "6:00 PM – 9:00 PM",
  "best_time_note": "Tue–Fri perform best",
  "alt_text": "Descriptive alt text for accessibility",
  "engagement_tip": "One sentence tip to boost engagement for this specific photo"
}

Rules:
- Write ALL 5 captions AND the alt_text natively in $language (not English then translated)
- hook_score is 1-10 (10 = irresistible scroll-stopper opening line)
- scores: rate each caption 0-100 on exactly these 5 axes: scroll_stop (how powerfully the opening line interrupts the scroll — curiosity, pattern-break, visual hook), aesthetic (visual/sensory language quality and image-caption harmony), authenticity (genuine human voice vs generic/AI-sounding), social_seo (keyword richness, trending phrases, hashtag-ready language), engagement (predicted comment/share/save likelihood based on CTA clarity, question-bait, value-offer)
- master_score: round(average of the 5 scores) as an integer 0-100
- Provide EXACTLY 20 hashtags split as: 8 "sweet" (niche, 10K–500K posts), 7 "comp" (competitive, 500K–2M posts), 5 "sat" (oversaturated, 2M+ posts)
- hashtag tags: lowercase, no spaces, no # symbol
- Each caption must follow the $platform guidelines above
- Each caption must be meaningfully different (different hook style, angle, length, structure)
- best_time should be specific to $platform
- alt_text should be WCAG-compliant, describe what is visually in the image
PROMPT;

// ── Call OpenAI API ────────────────────────────────────────────────────────
$payload = [
    'model'       => 'gpt-4o-mini',
    'max_tokens'  => 2500,
    'temperature' => 0.8,
    'messages'    => [
        [
            'role'    => 'system',
            'content' => $systemPrompt
        ],
        [
            'role'    => 'user',
            'content' => [
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url'    => "data:image/$imageType;base64,$imageBase64",
                        'detail' => 'low'   // 'low' = fast + cheap; use 'high' if quality issues
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => $userPrompt
                ]
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

// ── Handle cURL / HTTP errors ──────────────────────────────────────────────
if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Network error contacting AI service', 'detail' => $curlError]);
    exit;
}

if ($httpCode !== 200) {
    $errBody = json_decode($rawResponse, true);
    $errMsg  = $errBody['error']['message'] ?? "OpenAI returned HTTP $httpCode";
    http_response_code(500);
    echo json_encode(['error' => 'AI service error', 'detail' => $errMsg]);
    exit;
}

// ── Parse OpenAI response ──────────────────────────────────────────────────
$openAiResponse = json_decode($rawResponse, true);
$aiText = $openAiResponse['choices'][0]['message']['content'] ?? '';

// Strip markdown code fences if model wrapped it
$aiText = preg_replace('/^```json\s*/i', '', trim($aiText));
$aiText = preg_replace('/\s*```$/i', '', $aiText);
$aiText = trim($aiText);

$result = json_decode($aiText, true);

if (!$result || !isset($result['captions']) || !isset($result['hashtags'])) {
    // Attempt to extract JSON if there's surrounding text
    if (preg_match('/\{[\s\S]+\}/m', $aiText, $matches)) {
        $result = json_decode($matches[0], true);
    }
    if (!$result) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not parse AI response', 'raw' => substr($aiText, 0, 300)]);
        exit;
    }
}

// ── Validate & sanitize output ─────────────────────────────────────────────
// Ensure exactly 5 captions
if (!isset($result['captions']) || count($result['captions']) < 1) {
    http_response_code(500);
    echo json_encode(['error' => 'AI returned no captions']);
    exit;
}
// Cap to 5
$result['captions'] = array_slice($result['captions'], 0, 5);

// Ensure each caption has required fields
foreach ($result['captions'] as &$cap) {
    if (!isset($cap['text']))       $cap['text'] = '';
    if (!isset($cap['hook_score'])) $cap['hook_score'] = 7;
    $cap['hook_score'] = max(1, min(10, (int)$cap['hook_score']));
    // Validate post performance scores
    $axes = ['scroll_stop','aesthetic','authenticity','social_seo','engagement'];
    if (!isset($cap['scores']) || !is_array($cap['scores'])) {
        $cap['scores'] = array_fill_keys($axes, 70);
    }
    foreach ($axes as $ax) {
        $cap['scores'][$ax] = max(0, min(100, (int)($cap['scores'][$ax] ?? 70)));
    }
    if (!isset($cap['master_score'])) {
        $cap['master_score'] = (int)round(array_sum(array_map(fn($ax) => $cap['scores'][$ax], $axes)) / 5);
    }
    $cap['master_score'] = max(0, min(100, (int)$cap['master_score']));
}
unset($cap);

// Ensure 20 hashtags max
if (isset($result['hashtags'])) {
    $result['hashtags'] = array_slice($result['hashtags'], 0, 20);
    foreach ($result['hashtags'] as &$h) {
        if (!isset($h['tag']))  $h['tag']  = '';
        if (!isset($h['tier'])) $h['tier'] = 'comp';
        $h['tag']  = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $h['tag']));
        if (!in_array($h['tier'], ['sweet','comp','sat'])) $h['tier'] = 'comp';
    }
    unset($h);
}

// Defaults for optional fields
if (!isset($result['best_time']))      $result['best_time']      = '6:00 PM – 9:00 PM';
if (!isset($result['best_time_note'])) $result['best_time_note'] = 'Peak engagement window';
if (!isset($result['alt_text']))       $result['alt_text']       = 'Photo shared on social media.';
if (!isset($result['engagement_tip'])) $result['engagement_tip'] = 'Ask a question to boost comments.';

// ── Save result to DB & log usage ─────────────────────────────────────────
if ($user) {
    $db = getDB();

    // Pad captions array to exactly 5 entries
    $caps = array_values($result['captions']);
    while (count($caps) < 5) $caps[] = ['text' => '', 'hook_score' => 0];

    // Hook scores as comma-separated string e.g. "9,8,8,7,7"
    $hookScores = implode(',', array_map(fn($c) => (int) $c['hook_score'], $caps));

    // Combined best-time string
    $bestTimeFull = ($result['best_time'] ?? '') .
                    (isset($result['best_time_note']) && $result['best_time_note']
                        ? ' — ' . $result['best_time_note']
                        : '');

    $stmt = $db->prepare(
        "INSERT INTO captions
           (user_id, platform, language, tone, context_text,
            caption_1, caption_2, caption_3, caption_4, caption_5,
            hook_scores, hashtags, alt_text, best_time, engagement_tip)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $user['id'],
        $platform, $language, $tone, $context,
        $caps[0]['text'], $caps[1]['text'], $caps[2]['text'],
        $caps[3]['text'], $caps[4]['text'],
        $hookScores,
        json_encode($result['hashtags']),
        $result['alt_text'],
        $bestTimeFull,
        $result['engagement_tip'],
    ]);

    // Log this generate action for rate-limit tracking
    $db->prepare("INSERT INTO usage_log (user_id, action) VALUES (?, 'generate')")
       ->execute([$user['id']]);

    // Fresh count for the usage response
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM usage_log
          WHERE user_id = ? AND action = 'generate' AND DATE(created_at) = CURDATE()"
    );
    $stmt->execute([$user['id']]);
    $usedNow = (int) $stmt->fetchColumn();

    $result['usage'] = [
        'used'       => $usedNow,
        'limit'      => $isPremium ? null : $aiDailyLimit,
        'remaining'  => $isPremium ? null : max(0, $aiDailyLimit - $usedNow),
        'is_premium' => $isPremium,
    ];
}

// ── Return result ──────────────────────────────────────────────────────────
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
PROMPT;
