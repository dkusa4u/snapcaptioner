<?php
/**
 * SnapCaption AI — /api/reformat-caption.php
 *
 * POST { caption, platform, original_platform }
 * → Reformats a caption for the target platform using GPT-4o-mini
 *
 * Requires: Premium, Pro, or Teams plan
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

cors();
$user = tokenAuth();
$uid  = (int)$user['id'];

// Premium+ required
if (!planHasTools($user['plan'] ?? 'free')) {
    json_out(['success' => false, 'message' => 'Platform Formatter requires a Premium or Pro plan.', 'upgrade' => true], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body             = json_decode(file_get_contents('php://input'), true) ?: [];
$caption          = trim($body['caption']           ?? '');
$target_platform  = trim($body['platform']          ?? 'Instagram');
$original_platform = trim($body['original_platform'] ?? '');

if (!$caption) {
    json_out(['success' => false, 'message' => 'Caption is required'], 400);
}

// Platform rules reference for the AI prompt
$platformRules = [
    'Instagram' => [
        'char_limit'  => 2200,
        'hashtags'    => '20–30 hashtags (5–9 in caption, rest in first comment)',
        'tone'        => 'aspirational, visual, engaging, use line breaks for readability',
        'cta'         => 'drive saves, shares, and comments',
        'emojis'      => 'moderate emoji use to add personality',
        'notes'       => 'Include a strong hook in the first line. Break into short paragraphs.',
    ],
    'TikTok' => [
        'char_limit'  => 2200,
        'hashtags'    => '3–7 hashtags (mix of niche + trending)',
        'tone'        => 'casual, fun, raw, authentic, use Gen-Z/millennial slang where appropriate',
        'cta'         => 'drive follows, duets, and "for you page" visibility',
        'emojis'      => 'heavy emoji use is normal and expected',
        'notes'       => 'Keep it short and punchy. First line must be scroll-stopping.',
    ],
    'LinkedIn' => [
        'char_limit'  => 3000,
        'hashtags'    => '3–5 highly relevant professional hashtags only',
        'tone'        => 'professional, insightful, thought-leadership, value-driven',
        'cta'         => 'drive comments, shares, and profile visits',
        'emojis'      => 'minimal emojis (only where truly relevant)',
        'notes'       => 'Lead with a bold insight or lesson. Use short paragraphs. End with a question to drive comments.',
    ],
    'Pinterest' => [
        'char_limit'  => 500,
        'hashtags'    => '2–5 descriptive keyword-rich hashtags',
        'tone'        => 'inspirational, descriptive, keyword-rich for search discoverability',
        'cta'         => 'drive saves and click-throughs to website',
        'emojis'      => 'minimal or no emojis',
        'notes'       => 'Focus on SEO keywords. Describe the visual. Keep it concise and scannable.',
    ],
    'Facebook' => [
        'char_limit'  => 63206,
        'hashtags'    => '1–3 hashtags maximum (or none)',
        'tone'        => 'conversational, warm, community-oriented, storytelling',
        'cta'         => 'drive reactions, comments, and shares',
        'emojis'      => 'moderate, conversational emoji use',
        'notes'       => 'Longer storytelling works here. Ask questions. Encourage sharing.',
    ],
    'Twitter/X' => [
        'char_limit'  => 280,
        'hashtags'    => '1–2 hashtags maximum (only if truly trending)',
        'tone'        => 'concise, witty, opinionated, direct, punchy',
        'cta'         => 'drive retweets, replies, and profile clicks',
        'emojis'      => 'sparing emoji use, only when it adds value',
        'notes'       => 'Every character counts. Lead with the most interesting point. No fluff.',
    ],
];

$rules = $platformRules[$target_platform] ?? $platformRules['Instagram'];
$originalCtx = $original_platform ? " (originally written for {$original_platform})" : '';

$prompt = <<<PROMPT
You are a social media expert who reformats captions for different platforms.

ORIGINAL CAPTION{$originalCtx}:
"{$caption}"

TARGET PLATFORM: {$target_platform}

PLATFORM RULES FOR {$target_platform}:
- Character limit: {$rules['char_limit']} characters
- Hashtags: {$rules['hashtags']}
- Tone: {$rules['tone']}
- CTA goal: {$rules['cta']}
- Emoji use: {$rules['emojis']}
- Notes: {$rules['notes']}

TASK: Reformat this caption specifically for {$target_platform}. Keep the core message and key ideas but adapt:
1. Length (must respect the character limit — for Twitter/X, stay under 280 chars TOTAL)
2. Tone and voice for this platform's audience
3. Hashtag count and style
4. Emoji usage
5. CTA and engagement hooks

Return ONLY a JSON object with this exact structure (no markdown, no extra text):
{
  "reformatted": "the full reformatted caption text with hashtags included",
  "char_count": 123,
  "hashtag_count": 5,
  "tip": "One specific tip about why this version works for {$target_platform}"
}
PROMPT;

// Call OpenAI
$payload = [
    'model'       => 'gpt-4o-mini',
    'messages'    => [
        ['role' => 'system', 'content' => 'You are a social media expert. Always return valid JSON only.'],
        ['role' => 'user',   'content' => $prompt],
    ],
    'temperature' => 0.7,
    'max_tokens'  => 600,
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_TIMEOUT => 30,
]);

$raw      = curl_exec($ch);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log('reformat-caption curl: ' . $curlErr);
    json_out(['success' => false, 'message' => 'API request failed'], 500);
}

$resp = json_decode($raw, true);
$text = trim($resp['choices'][0]['message']['content'] ?? '');

// Strip markdown code fences if present
$text = preg_replace('/^```json\s*/i', '', $text);
$text = preg_replace('/\s*```$/', '', $text);

$result = json_decode($text, true);
if (!$result || !isset($result['reformatted'])) {
    error_log('reformat-caption bad JSON: ' . $text);
    json_out(['success' => false, 'message' => 'Failed to reformat caption. Please try again.'], 500);
}

// Log usage
try {
    getDb()->prepare("INSERT INTO usage_log (user_id, action, platform, created_at) VALUES (?, 'reformat_caption', ?, NOW())")
           ->execute([$uid, $target_platform]);
} catch (Exception $e) { /* ignore */ }

json_out([
    'success'       => true,
    'reformatted'   => $result['reformatted'],
    'char_count'    => $result['char_count'] ?? strlen($result['reformatted']),
    'hashtag_count' => $result['hashtag_count'] ?? 0,
    'tip'           => $result['tip'] ?? '',
    'platform'      => $target_platform,
    'rules'         => [
        'char_limit'   => $rules['char_limit'],
        'hashtag_guide' => $rules['hashtags'],
    ],
]);
