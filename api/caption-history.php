<?php
/**
 * SnapCaption AI — /api/caption-history.php
 *
 * GET  ?page=1&limit=20&favorites=1   → list captions
 * POST { action:'favorite', id:N }    → toggle favorite
 * DELETE ?id=N                        → delete caption
 *
 * All routes require Bearer token auth.
 */

require_once __DIR__ . '/db.php';
cors();

$user = tokenAuth();
if (!$user) json_out(['success'=>false,'message'=>'Unauthorized'], 401);

$method = $_SERVER['REQUEST_METHOD'];

// ── GET — list history ────────────────────────────────────────────────────
if ($method === 'GET') {
    $page      = max(1, (int)($_GET['page']  ?? 1));
    $limit     = min(50, max(1, (int)($_GET['limit'] ?? 20)));
    $favOnly   = !empty($_GET['favorites']);
    $offset    = ($page - 1) * $limit;

    try {
        $db = getDB();
        $where = 'WHERE user_id = ?' . ($favOnly ? ' AND favorited = 1' : '');

        $countStmt = $db->prepare("SELECT COUNT(*) FROM captions $where");
        $countStmt->execute([$user['id']]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT id, platform, language, tone, context_text,
                   caption_1, caption_2, caption_3, caption_4, caption_5,
                   hook_scores, hashtags, alt_text, best_time,
                   engagement_tip, favorited, created_at
            FROM captions
            $where
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$user['id'], $limit, $offset]);
        $rows = $stmt->fetchAll();

        // Decode JSON hashtags
        foreach ($rows as &$row) {
            $row['hashtags']   = json_decode($row['hashtags'] ?? '[]', true);
            $row['hook_scores'] = $row['hook_scores']
                ? array_map('intval', explode(',', $row['hook_scores']))
                : [];
        }

        json_out([
            'success' => true,
            'data'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int)ceil($total / $limit),
        ]);

    } catch (PDOException $e) {
        error_log('caption-history GET: ' . $e->getMessage());
        json_out(['success'=>false,'message'=>'Server error'], 500);
    }
}

// ── POST — toggle favorite ────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $id     = (int)($body['id'] ?? 0);

    if ($action === 'favorite' && $id) {
        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT id, favorited FROM captions WHERE id=? AND user_id=? LIMIT 1');
            $stmt->execute([$id, $user['id']]);
            $row  = $stmt->fetch();
            if (!$row) json_out(['success'=>false,'message'=>'Not found'], 404);

            $newVal = $row['favorited'] ? 0 : 1;
            $db->prepare('UPDATE captions SET favorited=? WHERE id=?')->execute([$newVal, $id]);
            json_out(['success'=>true,'favorited'=>(bool)$newVal]);
        } catch (PDOException $e) {
            error_log('caption-history POST: ' . $e->getMessage());
            json_out(['success'=>false,'message'=>'Server error'], 500);
        }
    }

    json_out(['success'=>false,'message'=>'Unknown action'], 400);
}

// ── DELETE — remove caption ───────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_out(['success'=>false,'message'=>'Missing id'], 400);

    try {
        $db   = getDB();
        $stmt = $db->prepare('DELETE FROM captions WHERE id=? AND user_id=?');
        $stmt->execute([$id, $user['id']]);
        json_out(['success' => true, 'deleted' => (bool)$stmt->rowCount()]);
    } catch (PDOException $e) {
        error_log('caption-history DELETE: ' . $e->getMessage());
        json_out(['success'=>false,'message'=>'Server error'], 500);
    }
}

json_out(['success'=>false,'message'=>'Method not allowed'], 405);
