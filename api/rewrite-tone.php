<?php
/**
 * rewrite-tone.php — Tone Toggle (Cringe Radar) endpoint
 * Rewrites existing captions in a new tone without regenerating hashtags/metadata.
 * Called by: app.html rewriteTone() function
 * Method: POST JSON { captions: string[], tone_key: string, tone_instruction: string }
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Optional auth check (same as other endpoints — allow both free and premium)
$authToken = null;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer (.+)$/i', $authHeader, $m)) {
    $authToken = $m[1];
}
// (Auth check intentionally lenient for tone rewrite — captions are already generated)

$body = json_decode(file_get_contents('php://input'), true);
$captions = $body['captions'] ?? [];
$toneInstruction = $body['tone_instruction'] ?? '';
$toneKey = $body['tone_key'] ?? '';

if (!is_array($captions) || count($captions) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No captions provided']);
    exit;
}
if (!$toneInstruction) {
    http_response_code(400);
    echo json_encode(['error' => 'No tone instruction provided']);
    exit;
}

// Build numbered list for the prompt
$captionList = '';
foreach ($captions as $i => $cap) {
    $captionList .= ($i + 1) . '. ' . $cap . "\n";
}

$systemPrompt = 'You are a social media caption rewriter. You rewrite captions to match a specific tone while preserving the core meaning and any hashtags. Output ONLY a JSON array of rewritten caption strings, no commentary, no markdown, no extra keys. Example: ["caption 1","caption 2","caption 3"]';

$userPrompt = "Rewrite the following captions in this new tone:\n\n{$toneInstruction}\n\nOriginal captions:\n{$captionList}\nReturn a JSON array with exactly " . count($captions) . " rewritten captions, in the same order.";

$apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : (defined('OPENAI_KEY') ? OPENAI_KEY : '');
if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API key not configured']);
    exit;
}

$payload = json_encode([
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt]
    ],
    'max_tokens' => 800,
    'temperature' => 0.75,
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_TIMEOUT        => 25,
]);
$response = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'OpenAI API error', 'detail' => substr($response, 0, 200)]);
    exit;
}

$oai = json_decode($response, true);
$rawContent = $oai['choices'][0]['message']['content'] ?? '';

// Strip markdown code fences if present
$rawContent = preg_replace('/^```json\s*/i', '', $rawContent);
$rawContent = preg_replace('/^```\s*/i', '', $rawContent);
$rawContent = preg_replace('/\s*```$/i', '', $rawContent);
$rawContent = trim($rawContent);

$rewritten = json_decode($rawContent, true);
if (!is_array($rewritten)) {
    // Fallback: return originals unchanged
    echo json_encode(['captions' => $captions, 'fallback' => true]);
    exit;
}

// Ensure same count
$rewritten = array_slice($rewritten, 0, count($captions));
while (count($rewritten) < count($captions)) {
    $rewritten[] = $captions[count($rewritten)];
}

echo json_encode(['captions' => array_values($rewritten)]);
