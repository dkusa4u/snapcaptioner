<?php
/**
 * upload-temp.php — Accept a base64 image, store it temporarily, return a public URL.
 *
 * Used by the social publishing flow: Instagram and Pinterest require
 * a publicly accessible image URL, not a base64 string.
 *
 * Files are stored in /public_html/tmp-uploads/ and auto-cleaned after 2 hours.
 *
 * POST body (JSON):  { "image_b64": "<base64 string>", "ext": "jpg" }
 * Returns:           { "success": true, "url": "https://snapcaptioner.com/tmp-uploads/xxx.jpg" }
 *
 * Rate limited to Pro/Teams users (same gate as publish).
 */
require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

$user = tokenAuth();
if (!$user) json_out(['success' => false, 'error' => 'unauthenticated'], 401);
if (!in_array($user['plan'], ['pro','teams'], true))
    json_out(['success' => false, 'error' => 'pro_required'], 403);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$b64    = trim($body['image_b64'] ?? '');
$ext    = preg_replace('/[^a-z]/', '', strtolower($body['ext'] ?? 'jpg'));

if (!$b64) json_out(['success' => false, 'error' => 'image_b64 required'], 400);
if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) $ext = 'jpg';

// Strip data-URL prefix if present
if (preg_match('/^data:image\/[^;]+;base64,(.+)$/', $b64, $m)) {
    $b64 = $m[1];
}

$decoded = base64_decode($b64, true);
if (!$decoded || strlen($decoded) < 100)
    json_out(['success' => false, 'error' => 'invalid_image'], 400);
if (strlen($decoded) > 10 * 1024 * 1024) // 10 MB max
    json_out(['success' => false, 'error' => 'image_too_large', 'message' => 'Image must be under 10 MB'], 400);

// Upload directory (siblings of api/ inside public_html)
$uploadDir = __DIR__ . '/../tmp-uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    // Protect against directory listing
    file_put_contents($uploadDir . '/.htaccess', "Options -Indexes\n");
}

// Clean files older than 2 hours
foreach (glob($uploadDir . '/*.{jpg,jpeg,png,webp}', GLOB_BRACE) as $f) {
    if (filemtime($f) < time() - 7200) @unlink($f);
}

$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$filepath = $uploadDir . '/' . $filename;

if (file_put_contents($filepath, $decoded) === false)
    json_out(['success' => false, 'error' => 'write_failed'], 500);

$url = 'https://snapcaptioner.com/tmp-uploads/' . $filename;
json_out(['success' => true, 'url' => $url, 'expires_in' => 7200]);
