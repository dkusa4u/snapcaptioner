<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

cors();
$user = tokenAuth();
$uid  = (int)$user['id'];

// Pro or Teams plan required
if (!planIsPro($user['plan'] ?? 'free')) {
    http_response_code(403);
    json_out(['success' => false, 'message' => 'Hashtag Research requires a Pro plan.', 'upgrade' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_out(['success' => false, 'message' => 'Method not allowed']);
}

$body     = json_decode(file_get_contents('php://input'), true) ?: [];
$topic    = trim($body['topic']    ?? '');
$platform = trim($body['platform'] ?? 'Instagram');
$niche    = trim($body['niche']    ?? '');
$count    = max(10, min(50, (int)($body['count'] ?? 30)));

if (!$topic) {
    http_response_code(400);
    json_out(['success' => false, 'message' => 'Topic is required']);
}

// Log usage
$db = getDb();
try {
    $db->prepare("INSERT INTO usage_log (user_id, action, platform, created_at) VALUES (?, 'hashtag_research', ?, NOW())")
       ->execute([$uid, $platform]);
} catch (Exception $e) { /* ignore */ }

// ── Step 1: GPT-generated hashtag list ───────────────────────────────────────
$nicheCtx = $niche ? " The user's niche/industry is: {$niche}." : '';
$prompt = <<<PROMPT
You are a social media hashtag research expert. Generate a comprehensive, high-quality hashtag strategy for the following:

Topic: {$topic}
Platform: {$platform}{$nicheCtx}
Total hashtags requested: {$count}

Return ONLY a valid JSON object (no markdown, no explanation) with this exact structure:
{
  "niche_specific": [
    {"tag": "#example", "reach": "12.5K", "difficulty": "low", "relevance": 95}
  ],
  "trending": [
    {"tag": "#example", "reach": "2.3M", "difficulty": "high", "relevance": 80}
  ],
  "broad": [
    {"tag": "#example", "reach": "45M", "difficulty": "high", "relevance": 60}
  ],
  "long_tail": [
    {"tag": "#example", "reach": "3.2K", "difficulty": "low", "relevance": 98}
  ],
  "strategy_tip": "One sentence tip about how to combine these hashtag tiers for maximum reach.",
  "best_count": 15
}

Rules:
- Distribute the {$count} hashtags across the 4 groups proportionally: ~30% niche, ~25% trending, ~20% broad, ~25% long-tail
- All tags must start with #, no spaces, lowercase
- reach: use K/M suffix (e.g. "45K", "1.2M")
- difficulty: exactly one of "low", "medium", "high"
- relevance: 0-100 integer score
- best_count: ideal number of hashtags to use per post for {$platform} (Instagram: 15-20, TikTok: 5-8, LinkedIn: 3-5, Twitter/X: 1-3, Facebook: 5-10)
- Make hashtags genuinely useful and specific to the topic — avoid generic filler
- For {$platform}, consider platform-specific hashtag culture and norms
PROMPT;

$payload = [
    'model'       => 'gpt-4o-mini',
    'temperature' => 0.7,
    'messages'    => [
        ['role' => 'system', 'content' => 'You are a hashtag research expert. Return only valid JSON.'],
        ['role' => 'user',   'content' => $prompt],
    ],
    'response_format' => ['type' => 'json_object'],
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

$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    http_response_code(502);
    json_out(['success' => false, 'message' => 'AI service error. Please try again.']);
}

$resp    = json_decode($raw, true);
$content = $resp['choices'][0]['message']['content'] ?? '';
$result  = json_decode($content, true);

if (!$result || !isset($result['niche_specific'])) {
    http_response_code(500);
    json_out(['success' => false, 'message' => 'Could not parse hashtag results. Please try again.']);
}

// ── Step 2: RapidAPI live volume enrichment (Instagram only, if key set) ─────
// Enriches niche_specific + trending tags with real post counts.
// Falls back gracefully — GPT estimates used if RapidAPI unavailable.
$liveDataEnabled = false;
if (
    defined('RAPIDAPI_KEY') &&
    RAPIDAPI_KEY !== '' &&
    strtolower($platform) === 'instagram'
) {
    // Collect tags to enrich (niche + trending — most valuable groups)
    $tagsToEnrich = array_merge(
        array_slice($result['niche_specific'] ?? [], 0, 8),
        array_slice($result['trending']       ?? [], 0, 7)
    );

    if (!empty($tagsToEnrich)) {
        $volumeMap = _fetchHashtagVolumes($tagsToEnrich);

        // Apply real data to niche_specific
        foreach ($result['niche_specific'] as &$tag) {
            $rawTag = ltrim($tag['tag'], '#');
            if (isset($volumeMap[$rawTag])) {
                $tag['reach']       = _formatCount($volumeMap[$rawTag]);
                $tag['post_count']  = $volumeMap[$rawTag];
                $tag['difficulty']  = _volumeToDifficulty($volumeMap[$rawTag]);
                $tag['live']        = true;
            }
        }
        unset($tag);

        // Apply real data to trending
        foreach ($result['trending'] as &$tag) {
            $rawTag = ltrim($tag['tag'], '#');
            if (isset($volumeMap[$rawTag])) {
                $tag['reach']       = _formatCount($volumeMap[$rawTag]);
                $tag['post_count']  = $volumeMap[$rawTag];
                $tag['difficulty']  = _volumeToDifficulty($volumeMap[$rawTag]);
                $tag['live']        = true;
            }
        }
        unset($tag);

        $liveDataEnabled = !empty($volumeMap);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Fetch real post counts from Instagram via RapidAPI (curl_multi parallel).
 * Returns [ 'tagname' => int_count, ... ]
 */
function _fetchHashtagVolumes(array $tags): array {
    $results = [];
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($tags as $t) {
        $rawTag = ltrim($t['tag'], '#');
        $url    = 'https://instagram-scraper-api2.p.rapidapi.com/v1/hashtag?hashtag=' . urlencode($rawTag);
        $ch     = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'x-rapidapi-host: instagram-scraper-api2.p.rapidapi.com',
                'x-rapidapi-key: ' . RAPIDAPI_KEY,
            ],
            CURLOPT_TIMEOUT => 8,
        ]);
        $handles[$rawTag] = $ch;
        curl_multi_add_handle($mh, $ch);
    }

    // Execute all requests in parallel
    $active = null;
    do {
        curl_multi_exec($mh, $active);
        curl_multi_select($mh);
    } while ($active > 0);

    // Collect results
    foreach ($handles as $rawTag => $ch) {
        $body     = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($httpCode === 200 && $body) {
            $data = json_decode($body, true);
            // Instagram scraper API response: data.edge_hashtag_to_media.count
            $count = $data['data']['edge_hashtag_to_media']['count']
                  ?? $data['data']['media_count']
                  ?? $data['data']['count']
                  ?? null;
            if ($count !== null && is_numeric($count)) {
                $results[$rawTag] = (int)$count;
            }
        }
    }

    curl_multi_close($mh);
    return $results;
}

/**
 * Format a raw post count into human-readable reach string.
 */
function _formatCount(int $n): string {
    if ($n >= 1_000_000_000) return round($n / 1_000_000_000, 1) . 'B';
    if ($n >= 1_000_000)     return round($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000)         return round($n / 1_000, 1) . 'K';
    return (string)$n;
}

/**
 * Map post count to difficulty tier.
 */
function _volumeToDifficulty(int $count): string {
    if ($count >= 5_000_000)  return 'high';
    if ($count >= 500_000)    return 'medium';
    return 'low';
}

// ── Response ─────────────────────────────────────────────────────────────────
json_out([
    'success'          => true,
    'topic'            => $topic,
    'platform'         => $platform,
    'live_data'        => $liveDataEnabled,
    'niche_specific'   => $result['niche_specific'] ?? [],
    'trending'         => $result['trending']       ?? [],
    'broad'            => $result['broad']          ?? [],
    'long_tail'        => $result['long_tail']      ?? [],
    'strategy_tip'     => $result['strategy_tip']  ?? '',
    'best_count'       => (int)($result['best_count'] ?? 15),
]);
