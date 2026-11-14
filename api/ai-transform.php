<?php
/**
 * SnapCaption AI — POST /api/ai-transform.php
 * Premium-only AI photo transforms via OpenAI gpt-image-1 edit API.
 *
 * Request body (JSON):
 *   { "image": "<base64 string>", "transform": "<transform_key>" }
 *
 * Response (JSON):
 *   { success, image (base64), transform, usage: { used, limit, remaining } }
 */

require_once __DIR__ . '/db.php';
cors();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'method_not_allowed'], 405);
}

// ── Auth: premium only ───────────────────────────────────
$user = tokenAuth();
if (!$user) {
    json_out([
        'error'   => 'login_required',
        'message' => 'Sign in to use AI photo transforms.',
    ], 401);
}

$isPremium = in_array($user['plan'] ?? '', ['premium', 'teams']);
if (!$isPremium) {
    json_out([
        'error'       => 'premium_required',
        'message'     => 'AI Photo Transforms are a Premium feature.',
        'upgrade_url' => '/pricing.html',
    ], 403);
}

// ── Rate limit ───────────────────────────────────────────
$dailyLimit = defined('AI_TRANSFORM_DAILY') ? AI_TRANSFORM_DAILY : 5;

try {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM usage_log
         WHERE user_id = ? AND action = 'transform' AND DATE(created_at) = CURDATE()"
    );
    $stmt->execute([$user['id']]);
    $usedToday = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('ai-transform rate-limit: ' . $e->getMessage());
    $usedToday = 0;
}

if ($usedToday >= $dailyLimit) {
    json_out([
        'error'   => 'limit_reached',
        'message' => "You've used all {$dailyLimit} AI transforms for today. Resets at midnight.",
        'used'    => $usedToday,
        'limit'   => $dailyLimit,
    ], 429);
}

// ── Parse body ───────────────────────────────────────────
$body      = json_decode(file_get_contents('php://input'), true);
$imageB64  = $body['image']     ?? '';
$transform = $body['transform'] ?? '';

if (!$imageB64 || !$transform) {
    json_out(['error' => 'missing_params', 'message' => 'image and transform are required.'], 400);
}

// ── Prompt map ───────────────────────────────────────────
$prompts = [
    // 🌍 Landmark Backgrounds
    'eiffel'       => 'Replace only the background with the Eiffel Tower in Paris at golden hour. Keep the person or subject in the foreground exactly as they are. Make it look like a real travel photograph. Photorealistic.',
    'nyc_night'    => 'Replace only the background with Times Square, New York City at night with colorful neon lights and busy streets. Keep the foreground subject exactly unchanged. Photorealistic travel photo style.',
    'maldives'     => 'Replace only the background with a crystal-clear turquoise Maldives beach, white sand, and clear blue sky. Keep the foreground subject completely unchanged. Photorealistic.',
    'tokyo_sakura' => 'Replace only the background with a Tokyo cherry blossom park in full bloom, beautiful pink sakura trees. Keep the foreground subject exactly as they are. Photorealistic.',
    'swiss_alps'   => 'Replace only the background with the Swiss Alps, dramatic snow-capped mountains and clear blue sky. Keep the foreground subject unchanged. Photorealistic.',
    'dubai'        => 'Replace only the background with the Dubai skyline at sunset, the Burj Khalifa clearly visible, warm golden tones. Keep the foreground subject exactly as they are. Photorealistic.',
    'bali'         => 'Replace only the background with a lush tropical Bali jungle, vibrant green plants and tropical scenery. Keep the foreground subject unchanged. Photorealistic.',
    'paris_cafe'   => 'Replace only the background with a charming Paris street cafe scene, cobblestone streets, classic French architecture and outdoor seating. Keep the foreground subject exactly unchanged. Photorealistic.',

    // ☀️ Weather & Lighting
    'golden_hour'  => 'Transform the lighting to warm golden hour sunset. Add a warm orange-gold glow, soft lens flare, and beautiful warm sky. Keep the subject and composition identical, only change the lighting and atmosphere.',
    'sunny'        => 'Replace any overcast, cloudy, or plain sky with a bright sunny day. Clear blue sky with fluffy white clouds. Enhance the brightness and warmth of the whole scene. Keep the subject and foreground unchanged.',
    'winter'       => 'Transform the scene to a winter wonderland. Add snow on the ground and softly falling snowflakes, cool blue tones, frost on surfaces. Keep the subject unchanged and looking natural in the scene.',
    'moody_rain'   => 'Add a dramatic moody rainy atmosphere: wet reflective ground, dark dramatic sky, light cinematic rain effect. Cinematic and atmospheric feel. Keep the subject unchanged.',

    // 🎨 Style & Aesthetic
    'cinematic'    => 'Apply cinematic color grading: rich deep shadows, slightly desaturated mids, warm and teal highlights, letterbox widescreen feel. Film-like quality. Keep all subjects and composition completely identical.',
    'dreamy'       => 'Apply a dreamy pastel aesthetic: soft overexposed light, peach and blush pink tones, hazy romantic glow, slightly ethereal feel. Keep all subjects and composition identical.',
    'dark_moody'   => 'Apply a dark moody artistic edit: deep rich shadows, high contrast, desaturated muted tones, dramatic atmospheric vignette. Keep all subjects and composition identical.',
    'film_grain'   => 'Apply a vintage 35mm film photography look: realistic film grain texture, slightly faded and muted colors, warm amber tones, subtle light leak. Keep all subjects and composition identical.',
    'studio_white' => 'Replace the entire background with a clean, pure white professional studio photography backdrop. Keep the subject exactly as they are. Professional portrait or product photography style.',

    // 🧹 AI Cleanup
    'remove_clutter' => 'Clean up and declutter the background of this photo. Remove any distracting objects, clutter, rubbish, or unwanted items in the background. Keep the main subject and foreground completely unchanged. Make the background look clean and natural.',
    'erase_people'   => 'Remove any people visible in the background of this photo, keeping only the main foreground subject. Fill the empty background naturally and seamlessly. Keep the main subject completely unchanged.',
    'skin_retouch'   => 'Subtly and naturally retouch the portrait. Smooth skin texture and tone, reduce visible blemishes, soften under-eye shadows. Keep the person looking completely natural and realistic — avoid over-editing or plastic appearance. Preserve all facial features and identity.',

    // 😎 Style & Accessories (NEW)
    'add_sunglasses'    => 'Add a pair of stylish, fashionable sunglasses to the person\'s face in this photo. Choose sunglasses that naturally complement their face shape and the lighting. The sunglasses should look naturally worn, with realistic reflections and shadows. Keep everything else in the photo completely unchanged.',
    'add_cap'           => 'Add a stylish baseball cap or snapback hat to the person\'s head. Choose a color that complements their outfit. The cap should sit naturally on their head with realistic shadows and lighting. Keep everything else in the photo completely unchanged.',
    'casual_outfit'     => 'Change the person\'s clothing to a stylish casual outfit — think clean fitted jeans, a relaxed t-shirt or hoodie, fresh sneakers. The outfit should suit their body type and the lighting of the scene. Keep their face, hair, pose, and background completely unchanged. Photorealistic.',
    'formal_outfit'     => 'Change the person\'s clothing to a sharp, professional formal outfit — a well-fitted suit, blazer, or business attire appropriate for their apparent gender. Keep their face, hair, pose, and background completely unchanged. Photorealistic, high-quality fabric details.',
    'streetwear_style'  => 'Change the person\'s clothing to a trendy streetwear outfit — oversized hoodie, cargo pants or joggers, fresh sneakers, possibly a cap. Urban and fashion-forward. Keep their face, hair, pose, and background completely unchanged. Photorealistic.',

    // 💼 Professional Headshots (NEW)
    'linkedin_headshot' => 'Transform this into a professional LinkedIn-style headshot. Apply clean studio lighting — soft key light, gentle fill. Replace the background with a smooth neutral grey or soft blurred office environment. Lightly retouch the skin for a polished look without looking artificial. Keep the person\'s face, expression, and identity completely unchanged. Professional business portrait quality.',
    'dating_profile'    => 'Transform this into an attractive, natural-looking dating profile photo. Apply warm, flattering portrait lighting with a soft bokeh background blur. Enhance the natural glow of the skin subtly. Keep the person\'s face and expression completely authentic — no dramatic changes. Warm, inviting, and genuine feeling.',
    'author_headshot'   => 'Transform this into an authoritative author or speaker headshot. Apply dramatic studio-style Rembrandt lighting with a dark, moody background. Give it a confident, intellectual feel. Lightly retouch the skin. Keep all facial features and identity completely unchanged. Editorial quality portrait.',

    // ✨ Portrait Enhancement (NEW)
    'glow_up'           => 'Apply a full portrait enhancement: smooth and even out skin tone, brighten the eyes, enhance facial definition, add flattering soft lighting. The result should look like a professional magazine portrait — polished, vibrant, and confident. Keep all facial features and identity completely unchanged. Natural, not plastic.',
    'retro_portrait'    => 'Apply a warm vintage 1970s portrait photography look: warm amber and golden tones, slightly elevated blacks, gentle soft focus, beautiful film-like quality. Keep the person and composition completely unchanged.',
    'editorial_bw'      => 'Convert this portrait into a striking high-contrast black and white editorial photograph. Deep rich blacks, clean bright highlights, sharp detail on the face and eyes. Professional fashion editorial or magazine cover quality. Keep all composition unchanged.',
];

if (!isset($prompts[$transform])) {
    json_out(['error' => 'unknown_transform', 'message' => 'Unknown transform type: ' . $transform], 400);
}

// ── Decode image → temp PNG ───────────────────────────────
// Strip data URL prefix if present
$imageB64  = preg_replace('/^data:image\/[a-z]+;base64,/', '', $imageB64);
$imageData = base64_decode($imageB64, true);

if (!$imageData || strlen($imageData) < 100) {
    json_out(['error' => 'invalid_image', 'message' => 'Invalid or empty image data.'], 400);
}

$tmpFile = tempnam(sys_get_temp_dir(), 'scimg_') . '.png';
if (file_put_contents($tmpFile, $imageData) === false) {
    json_out(['error' => 'server_error', 'message' => 'Could not write temp file.'], 500);
}

// ── Call OpenAI /v1/images/edits ─────────────────────────
$ch = curl_init('https://api.openai.com/v1/images/edits');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_POSTFIELDS => [
        'model'   => 'gpt-image-1',
        'image[]' => new CURLFile($tmpFile, 'image/png', 'image.png'),
        'prompt'  => $prompts[$transform],
        'size'    => '1024x1024',
        'n'       => '1',
    ],
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

@unlink($tmpFile); // always clean up temp file

if ($response === false || $curlErr) {
    error_log('ai-transform curl error: ' . $curlErr);
    json_out(['error' => 'network_error', 'message' => 'Could not reach OpenAI API. Please try again.'], 502);
}

$data = json_decode($response, true);

if ($httpCode !== 200 || empty($data['data'])) {
    $errMsg = $data['error']['message'] ?? 'OpenAI API returned an error. Please try again.';
    error_log("ai-transform openai error ({$httpCode}): " . $errMsg);
    json_out(['error' => 'openai_error', 'message' => $errMsg], 502);
}

// ── Log usage ────────────────────────────────────────────
try {
    $logStmt = $db->prepare(
        "INSERT INTO usage_log (user_id, action, created_at) VALUES (?, 'transform', NOW())"
    );
    $logStmt->execute([$user['id']]);
    $usedNow = $usedToday + 1;
} catch (PDOException $e) {
    error_log('ai-transform log error: ' . $e->getMessage());
    $usedNow = $usedToday + 1;
}

// ── Extract result image ─────────────────────────────────
// gpt-image-1 returns b64_json
$resultB64 = $data['data'][0]['b64_json'] ?? '';

if (!$resultB64) {
    // Fallback: download from URL if returned
    $url = $data['data'][0]['url'] ?? '';
    if ($url) {
        $imgContent = @file_get_contents($url);
        $resultB64  = $imgContent ? base64_encode($imgContent) : '';
    }
}

if (!$resultB64) {
    json_out(['error' => 'no_image', 'message' => 'OpenAI returned no image data.'], 502);
}

// ── Success ──────────────────────────────────────────────
json_out([
    'success'   => true,
    'image'     => $resultB64,
    'transform' => $transform,
    'usage'     => [
        'used'      => $usedNow,
        'limit'     => $dailyLimit,
        'remaining' => max(0, $dailyLimit - $usedNow),
    ],
]);
