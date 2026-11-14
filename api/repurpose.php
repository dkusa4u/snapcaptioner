<?php
/**
 * SnapCaption AI — /api/repurpose.php
 *
 * POST { url, platforms[], tone, language, brand_voice }
 *   → scrape URL → GPT-4o-mini → 5 captions per platform + hashtags
 *
 * GET ?job=<id> → poll async job status (future-proofing; currently sync)
 */

require_once __DIR__ . '/db.php';
cors();

$user = tokenAuth();
if (!$user) json_out(['success' => false, 'message' => 'Unauthorized'], 401);

// Premium gate — allow free users 1 repurpose/day
$isPremium = planHasTools($user['plan'] ?? 'free');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed'], 405);
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$rawUrl   = trim($body['url'] ?? '');
$tone     = trim($body['tone'] ?? 'engaging');
$language = trim($body['language'] ?? 'English');
$brandVoice = trim($body['brand_voice'] ?? ($user['brand_voice'] ?? ''));

// Validate URL
if (!$rawUrl) {
    json_out(['success' => false, 'message' => 'URL is required'], 400);
}
if (!filter_var($rawUrl, FILTER_VALIDATE_URL)) {
    json_out(['success' => false, 'message' => 'Please enter a valid URL'], 400);
}
$parsed = parse_url($rawUrl);
if (!in_array($parsed['scheme'] ?? '', ['http', 'https'])) {
    json_out(['success' => false, 'message' => 'Only http/https URLs are supported'], 400);
}

// Daily limit for free users
if (!$isPremium) {
    try {
        $db = getDB();
        $today = date('Y-m-d');
        $stmt = $db->prepare("SELECT COUNT(*) FROM repurpose_jobs WHERE user_id=? AND DATE(created_at)=?");
        $stmt->execute([$user['id'], $today]);
        $count = (int)$stmt->fetchColumn();
        if ($count >= 2) {
            json_out(['success' => false, 'message' => 'Free plan: 2 repurposes/day. Upgrade for unlimited.', 'upgrade' => true], 429);
        }
    } catch (PDOException $e) {
        // Table may not exist yet — that's fine, we create it below
    }
}

// ── 1. Scrape URL ──────────────────────────────────────────────────────────
$content = _scrapeUrl($rawUrl);
if (!$content || strlen($content) < 100) {
    json_out(['success' => false, 'message' => 'Could not extract enough content from that URL. Try pasting text directly instead.'], 422);
}

// Truncate to avoid token bloat (keep ~6000 chars ≈ ~1500 tokens)
$contentSnippet = mb_substr($content, 0, 6000);

// ── 2. Build GPT prompt ───────────────────────────────────────────────────
$platforms = ['Instagram', 'LinkedIn', 'Twitter/X', 'Facebook', 'TikTok'];

$brandLine = $brandVoice
    ? "\nBrand voice: {$brandVoice}"
    : '';

$systemPrompt = <<<PROMPT
You are SnapCaption AI, an expert social media content strategist.
Your task: repurpose an article/blog/URL into platform-optimised captions.
Return ONLY valid JSON — no markdown, no extra text.
PROMPT;

$userPrompt = <<<PROMPT
Repurpose this content into 5 captions, one per platform:
Platforms: Instagram, LinkedIn, Twitter/X, Facebook, TikTok
Tone: {$tone}
Language: {$language}{$brandLine}

SOURCE CONTENT:
{$contentSnippet}

For each platform create a caption that:
- Instagram: storytelling hook, 150-220 words, 3-4 line breaks, emojis, 5 hashtags
- LinkedIn: professional insight, 120-180 words, thought-leader angle, NO emojis, 3 hashtags
- Twitter/X: punchy under 280 chars, strong hook, 2-3 hashtags
- Facebook: conversational, 100-150 words, question at end, 2 hashtags
- TikTok: Gen-Z casual energy, trending phrases, hook in first 5 words, 5 hashtags

Also generate:
- 20 niche hashtags (8 sweet 10K-500K, 7 comp 500K-2M, 5 sat 2M+)
- key_insight: single sentence takeaway from the article
- suggested_title: catchy repurposed title (max 10 words)

Return JSON exactly:
{
  "suggested_title": "...",
  "key_insight": "...",
  "captions": {
    "Instagram": "...",
    "LinkedIn": "...",
    "Twitter/X": "...",
    "Facebook": "...",
    "TikTok": "..."
  },
  "hashtags": {
    "sweet": ["tag1","tag2","tag3","tag4","tag5","tag6","tag7","tag8"],
    "comp":  ["tag1","tag2","tag3","tag4","tag5","tag6","tag7"],
    "sat":   ["tag1","tag2","tag3","tag4","tag5"]
  }
}
PROMPT;

// ── 3. Call OpenAI ────────────────────────────────────────────────────────
$apiKey  = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (getenv('OPENAI_API_KEY') ?: '');
if (!$apiKey) {
    json_out(['success' => false, 'message' => 'OpenAI API not configured'], 500);
}

$payload = json_encode([
    'model'       => 'gpt-4o-mini',
    'max_tokens'  => 2500,
    'temperature' => 0.78,
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
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$raw || $code !== 200) {
    error_log("repurpose OpenAI error $code: $raw");
    json_out(['success' => false, 'message' => 'AI generation failed. Please try again.'], 502);
}

$resp    = json_decode($raw, true);
$jsonStr = $resp['choices'][0]['message']['content'] ?? '';
$result  = json_decode($jsonStr, true);

if (!$result || empty($result['captions'])) {
    error_log("repurpose parse error: $jsonStr");
    json_out(['success' => false, 'message' => 'Could not parse AI response. Please try again.'], 500);
}

// Flatten hashtags array
$allHashtags = array_merge(
    $result['hashtags']['sweet'] ?? [],
    $result['hashtags']['comp']  ?? [],
    $result['hashtags']['sat']   ?? []
);

// ── 4. Store job in DB ────────────────────────────────────────────────────
try {
    $db = getDB();
    // Auto-create table
    $db->exec("
        CREATE TABLE IF NOT EXISTS repurpose_jobs (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            user_id      INT NOT NULL,
            source_url   VARCHAR(2000),
            source_title VARCHAR(500),
            key_insight  TEXT,
            captions     LONGTEXT,
            hashtags     TEXT,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rep_user (user_id)
        )
    ");
    $ins = $db->prepare("
        INSERT INTO repurpose_jobs (user_id, source_url, source_title, key_insight, captions, hashtags)
        VALUES (?,?,?,?,?,?)
    ");
    $ins->execute([
        $user['id'],
        $rawUrl,
        $result['suggested_title'] ?? '',
        $result['key_insight'] ?? '',
        json_encode($result['captions']),
        json_encode($allHashtags),
    ]);
} catch (PDOException $e) {
    error_log('repurpose DB: ' . $e->getMessage());
    // Non-fatal — still return results
}

// ── 5. Return ─────────────────────────────────────────────────────────────
json_out([
    'success'         => true,
    'suggested_title' => $result['suggested_title'] ?? '',
    'key_insight'     => $result['key_insight'] ?? '',
    'captions'        => $result['captions'],
    'hashtags'        => [
        'sweet' => $result['hashtags']['sweet'] ?? [],
        'comp'  => $result['hashtags']['comp']  ?? [],
        'sat'   => $result['hashtags']['sat']   ?? [],
        'all'   => $allHashtags,
    ],
    'source_url'      => $rawUrl,
]);


// ═══════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Scrape URL and return clean text content.
 * Strips HTML tags, scripts, styles; normalises whitespace.
 */
function _scrapeUrl(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SnapCaptionBot/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
    ]);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$html || $httpCode < 200 || $httpCode >= 400) {
        return '';
    }

    // Strip <script>, <style>, <nav>, <footer>, <header>, <aside>
    $html = preg_replace('/<(script|style|nav|footer|header|aside|noscript)[^>]*>.*?<\/\1>/si', '', $html);

    // Extract <title>
    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
        $title = trim(strip_tags($m[1])) . "\n\n";
    }

    // Extract meta description
    $metaDesc = '';
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/si', $html, $m)) {
        $metaDesc = trim($m[1]) . "\n\n";
    }

    // Extract OG description
    if (!$metaDesc && preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/si', $html, $m)) {
        $metaDesc = trim($m[1]) . "\n\n";
    }

    // Extract <article> or <main> if present, else <body>
    $body = '';
    if (preg_match('/<article[^>]*>(.*?)<\/article>/si', $html, $m)) {
        $body = $m[1];
    } elseif (preg_match('/<main[^>]*>(.*?)<\/main>/si', $html, $m)) {
        $body = $m[1];
    } elseif (preg_match('/<body[^>]*>(.*?)<\/body>/si', $html, $m)) {
        $body = $m[1];
    } else {
        $body = $html;
    }

    // Strip all tags
    $text = strip_tags($body);

    // Decode HTML entities
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Collapse whitespace
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    $text = trim($text);

    return $title . $metaDesc . $text;
}
