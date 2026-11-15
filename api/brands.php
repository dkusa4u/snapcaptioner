<?php
require_once __DIR__ . '/db.php';

cors();
$user = tokenAuth();
$uid  = (int)$user['id'];

// Pro plan required
if (!planIsPro($user['plan'] ?? 'free')) {
    http_response_code(403);
    json_out(['success' => false, 'message' => 'Multi-brand profiles require a Pro plan.', 'upgrade' => true]);
}

$db = getDb();

// Auto-create brands table
try {
    $db->exec("CREATE TABLE IF NOT EXISTS brands (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        name        VARCHAR(100) NOT NULL,
        tone        VARCHAR(60)  DEFAULT '',
        niche       VARCHAR(100) DEFAULT '',
        language    VARCHAR(60)  DEFAULT 'English',
        hashtags    TEXT         DEFAULT '[]',
        voice_notes TEXT         DEFAULT '',
        color       VARCHAR(10)  DEFAULT '#7c3aed',
        is_default  TINYINT(1)   DEFAULT 0,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* already exists */ }

$method = $_SERVER['REQUEST_METHOD'];

// ── GET — list brands ─────────────────────────────────────────────────────
if ($method === 'GET') {
    $rows = $db->prepare("SELECT * FROM brands WHERE user_id = ? ORDER BY is_default DESC, name ASC");
    $rows->execute([$uid]);
    $brands = $rows->fetchAll(PDO::FETCH_ASSOC);

    foreach ($brands as &$b) {
        $b['hashtags']  = json_decode($b['hashtags'] ?? '[]', true) ?: [];
        $b['id']        = (int)$b['id'];
        $b['is_default']= (bool)$b['is_default'];
    }

    json_out(['success' => true, 'brands' => $brands]);
}

// ── POST — create brand ───────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];

    $name       = trim($body['name']       ?? '');
    $tone       = trim($body['tone']       ?? '');
    $niche      = trim($body['niche']      ?? '');
    $language   = trim($body['language']   ?? 'English');
    $hashtags   = $body['hashtags']        ?? [];
    $voiceNotes = trim($body['voice_notes']?? '');
    $color      = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : '#7c3aed';
    $isDefault  = (bool)($body['is_default'] ?? false);

    if (!$name) {
        http_response_code(400);
        json_out(['success' => false, 'message' => 'Brand name is required']);
    }

    // Max 10 brands per Pro user
    $count = $db->prepare("SELECT COUNT(*) FROM brands WHERE user_id = ?");
    $count->execute([$uid]);
    if ((int)$count->fetchColumn() >= 10) {
        http_response_code(400);
        json_out(['success' => false, 'message' => 'Maximum 10 brand profiles allowed']);
    }

    // If setting as default, clear others
    if ($isDefault) {
        $db->prepare("UPDATE brands SET is_default = 0 WHERE user_id = ?")->execute([$uid]);
    }

    $stmt = $db->prepare("INSERT INTO brands (user_id, name, tone, niche, language, hashtags, voice_notes, color, is_default)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$uid, $name, $tone, $niche, $language, json_encode($hashtags), $voiceNotes, $color, $isDefault ? 1 : 0]);
    $newId = (int)$db->lastInsertId();

    json_out(['success' => true, 'id' => $newId, 'message' => 'Brand created']);
}

// ── PUT — update brand ────────────────────────────────────────────────────
if ($method === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($body['id'] ?? 0);

    if (!$id) { http_response_code(400); json_out(['success' => false, 'message' => 'Missing id']); }

    // Verify ownership
    $own = $db->prepare("SELECT id FROM brands WHERE id = ? AND user_id = ?");
    $own->execute([$id, $uid]);
    if (!$own->fetch()) {
        http_response_code(404);
        json_out(['success' => false, 'message' => 'Brand not found']);
    }

    $name       = trim($body['name']       ?? '');
    $tone       = trim($body['tone']       ?? '');
    $niche      = trim($body['niche']      ?? '');
    $language   = trim($body['language']   ?? 'English');
    $hashtags   = $body['hashtags']        ?? [];
    $voiceNotes = trim($body['voice_notes']?? '');
    $color      = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : '#7c3aed';
    $isDefault  = (bool)($body['is_default'] ?? false);

    if (!$name) {
        http_response_code(400);
        json_out(['success' => false, 'message' => 'Brand name is required']);
    }

    if ($isDefault) {
        $db->prepare("UPDATE brands SET is_default = 0 WHERE user_id = ?")->execute([$uid]);
    }

    $stmt = $db->prepare("UPDATE brands SET name=?, tone=?, niche=?, language=?, hashtags=?, voice_notes=?, color=?, is_default=? WHERE id=? AND user_id=?");
    $stmt->execute([$name, $tone, $niche, $language, json_encode($hashtags), $voiceNotes, $color, $isDefault ? 1 : 0, $id, $uid]);

    json_out(['success' => true, 'message' => 'Brand updated']);
}

// ── DELETE — delete brand ─────────────────────────────────────────────────
if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $id   = (int)($body['id'] ?? $_GET['id'] ?? 0);

    if (!$id) { http_response_code(400); json_out(['success' => false, 'message' => 'Missing id']); }

    $stmt = $db->prepare("DELETE FROM brands WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $uid]);

    json_out(['success' => true, 'message' => 'Brand deleted']);
}

http_response_code(405);
json_out(['success' => false, 'message' => 'Method not allowed']);
