<?php
/**
 * api/relight.php — AI Photo Relighting + Background Swap (Feature 5)
 * Uses Photoroom Image Editing API v2
 * Plan gate: Pro / Teams  |  Rate limit: 10/day
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

/* ── Auth ── */
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}
$token = $m[1];

$db   = getDB();
$stmt = $db->prepare('SELECT id, plan FROM users WHERE auth_token = ? LIMIT 1');
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(401); echo json_encode(['error' => 'Invalid token']); exit;
}

/* ── Plan gate: Pro / Teams ── */
$plan = $user['plan'] ?? 'free';
if (!in_array($plan, ['pro', 'teams'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Pro or Teams plan required for AI Photo Relighting']);
    exit;
}

/* ── Rate limit: 10 relights / day ── */
$userId   = (int)$user['id'];
$dailyCap = ($plan === 'teams') ? 20 : 10;

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM usage_log
     WHERE user_id = ? AND action = 'relight' AND created_at >= CURDATE()"
);
$stmt->execute([$userId]);
$todayCount = (int)$stmt->fetchColumn();

if ($todayCount >= $dailyCap) {
    http_response_code(429);
    echo json_encode([
        'error'     => "Daily limit reached ($dailyCap relights/day on {$plan} plan)",
        'remaining' => 0,
    ]);
    exit;
}

/* ── Parse body ── */
$body = json_decode(file_get_contents('php://input'), true);
if (!$body) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }

$imageB64        = $body['image_b64']        ?? '';
$lightingMode    = $body['lighting_mode']    ?? 'ai.auto';
$removeBackground= (bool)($body['remove_background'] ?? false);
$bgPrompt        = trim($body['bg_prompt']   ?? '');
$maxSize         = min((int)($body['max_size'] ?? 1200), 2000);

/* Validate lighting mode */
$validModes = ['ai.auto', 'ai.preserve-hue-and-saturation', 'ai.optimize-portrait'];
if (!in_array($lightingMode, $validModes, true)) {
    $lightingMode = 'ai.auto';
}

if (empty($imageB64)) {
    http_response_code(400); echo json_encode(['error' => 'image_b64 required']); exit;
}

/* Strip data URI prefix if present */
if (preg_match('/^data:image\/(\w+);base64,/', $imageB64, $ext)) {
    $imageExt = $ext[1] === 'jpeg' ? 'jpg' : $ext[1];
    $imageB64 = preg_replace('/^data:image\/\w+;base64,/', '', $imageB64);
} else {
    $imageExt = 'jpg';
}

/* Decode to temp file */
$imageData = base64_decode($imageB64);
if (!$imageData || strlen($imageData) < 100) {
    http_response_code(400); echo json_encode(['error' => 'Invalid image data']); exit;
}
if (strlen($imageData) > 30 * 1024 * 1024) {
    http_response_code(400); echo json_encode(['error' => 'Image too large (30 MB max)']); exit;
}

$tmpFile = sys_get_temp_dir() . '/snap_relight_' . uniqid() . '.' . $imageExt;
file_put_contents($tmpFile, $imageData);

/* ── Build multipart POST to Photoroom ── */
$apiKey = defined('PHOTOROOM_API_KEY') ? PHOTOROOM_API_KEY : '';
if (empty($apiKey)) {
    @unlink($tmpFile);
    http_response_code(500);
    echo json_encode(['error' => 'Photoroom API key not configured. Add PHOTOROOM_API_KEY to config.php']);
    exit;
}

$postFields = [
    'imageFile'         => new CURLFile($tmpFile, 'image/' . ($imageExt === 'jpg' ? 'jpeg' : $imageExt)),
    'referenceBox'      => 'originalImage',
    'maxWidth'          => (string)$maxSize,
    'maxHeight'         => (string)$maxSize,
    'removeBackground'  => $removeBackground ? 'true' : 'false',
    'lighting.mode'     => $lightingMode,
];

/* Add background prompt if removing background */
if ($removeBackground && !empty($bgPrompt)) {
    $postFields['background.prompt'] = $bgPrompt;
    $postFields['pr-ai-background-model-version'] = 'background-studio-beta-2025-03-17';
}

$ch = curl_init('https://image-api.photoroom.com/v2/edit');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . $apiKey,
        'Accept: image/png, image/jpeg, application/json',
    ],
]);

$response    = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$curlErr     = curl_error($ch);
curl_close($ch);

@unlink($tmpFile);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(['error' => 'Photoroom API connection error: ' . $curlErr]);
    exit;
}

/* Handle non-image response (usually JSON error from Photoroom) */
if ($httpCode !== 200 || strpos($contentType, 'image/') === false) {
    $errData = json_decode($response, true);
    $errMsg  = $errData['message'] ?? $errData['error'] ?? 'Photoroom API error (HTTP ' . $httpCode . ')';
    http_response_code($httpCode >= 400 && $httpCode < 600 ? $httpCode : 502);
    echo json_encode(['error' => $errMsg]);
    exit;
}

/* ── Success — convert response image to base64 ── */
$outputMime = strpos($contentType, 'png') !== false ? 'image/png' : 'image/jpeg';
$outputExt  = $outputMime === 'image/png'  ? 'png' : 'jpg';
$resultB64  = 'data:' . $outputMime . ';base64,' . base64_encode($response);

/* ── Log usage ── */
$logStmt = $db->prepare(
    "INSERT INTO usage_log (user_id, action, meta, created_at)
     VALUES (?, 'relight', ?, NOW())"
);
$logStmt->execute([$userId, json_encode([
    'mode'              => $lightingMode,
    'remove_background' => $removeBackground,
    'bg_prompt'         => $bgPrompt ?: null,
])]);

$remaining = $dailyCap - $todayCount - 1;

echo json_encode([
    'success'   => true,
    'image_b64' => $resultB64,
    'ext'       => $outputExt,
    'mode'      => $lightingMode,
    'remaining' => max(0, $remaining),
]);
