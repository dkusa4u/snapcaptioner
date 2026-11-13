<?php
/**
 * SnapCaption AI — /api/brand-voice.php
 *
 * GET  → return saved brand voice
 * POST { brand_voice: "..." } → save brand voice (Premium+ only)
 */

require_once __DIR__ . '/db.php';
cors();

$user = tokenAuth();
if (!$user) json_out(['success'=>false,'message'=>'Unauthorized'], 401);

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_out(['success'=>true,'brand_voice'=>$user['brand_voice'] ?? '']);
}

if ($method === 'POST') {
    if ($user['plan'] === 'free') {
        json_out(['success'=>false,'message'=>'Brand Voice Saver requires a Premium plan.'], 403);
    }

    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $voice = substr(trim($body['brand_voice'] ?? ''), 0, 1000);

    try {
        getDB()->prepare('UPDATE users SET brand_voice=? WHERE id=?')->execute([$voice ?: null, $user['id']]);
        json_out(['success'=>true,'brand_voice'=>$voice]);
    } catch (PDOException $e) {
        error_log('brand-voice: ' . $e->getMessage());
        json_out(['success'=>false,'message'=>'Server error'], 500);
    }
}

json_out(['success'=>false,'message'=>'Method not allowed'], 405);
