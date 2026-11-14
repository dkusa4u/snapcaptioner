<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// Auth
$token = null;
$ah = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/Bearer\s+(.+)/i', $ah, $m)) $token = trim($m[1]);
if (!$token) { http_response_code(401); echo json_encode(['error'=>'Unauthorized']); exit; }

$stmt = $pdo->prepare("SELECT id, plan FROM users WHERE auth_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(401); echo json_encode(['error'=>'Invalid token']); exit; }

$userId    = $user['id'];
$isPremium = ($user['plan'] === 'premium');

// Auto-create table
$pdo->exec("
CREATE TABLE IF NOT EXISTS caption_history (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT NOT NULL,
  captions     JSON NOT NULL,
  platform     VARCHAR(50)  DEFAULT 'instagram',
  language     VARCHAR(10)  DEFAULT 'en',
  status       ENUM('generated','used','skipped') DEFAULT 'generated',
  used_caption TEXT         DEFAULT NULL,
  likes        INT          DEFAULT NULL,
  comments     INT          DEFAULT NULL,
  reach        INT          DEFAULT NULL,
  perf_note    VARCHAR(255) DEFAULT NULL,
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  used_at      TIMESTAMP    NULL DEFAULT NULL,
  INDEX idx_ch_user (user_id),
  INDEX idx_ch_created (created_at)
)
");

$method = $_SERVER['REQUEST_METHOD'];
$action = '';
if ($method === 'GET')  $action = $_GET['action']  ?? 'history';
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
}

// ── GET history ──────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'history') {
    if (!$isPremium) {
        http_response_code(403);
        echo json_encode(['error'=>'premium_required']);
        exit;
    }
    $page  = max(1, (int)($_GET['page']  ?? 1));
    $limit = 20;
    $offset= ($page - 1) * $limit;

    $total = $pdo->prepare("SELECT COUNT(*) FROM caption_history WHERE user_id=?");
    $total->execute([$userId]);
    $totalRows = (int)$total->fetchColumn();

    $rows = $pdo->prepare("
        SELECT id, captions, platform, language, status,
               used_caption, likes, comments, reach, perf_note,
               created_at, used_at
        FROM caption_history
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $rows->execute([$userId, $limit, $offset]);
    $items = $rows->fetchAll(PDO::FETCH_ASSOC);

    // decode JSON captions field
    foreach ($items as &$row) {
        $row['captions'] = json_decode($row['captions'], true);
    }
    unset($row);

    echo json_encode([
        'items'      => $items,
        'total'      => $totalRows,
        'page'       => $page,
        'page_count' => ceil($totalRows / $limit),
    ]);
    exit;
}

// ── GET stats ─────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'stats') {
    if (!$isPremium) {
        http_response_code(403);
        echo json_encode(['error'=>'premium_required']);
        exit;
    }
    $st = $pdo->prepare("
        SELECT
          COUNT(*)                                 AS total_generated,
          SUM(status='used')                       AS total_used,
          SUM(status='skipped')                    AS total_skipped,
          AVG(NULLIF(likes,NULL))                  AS avg_likes,
          AVG(NULLIF(comments,NULL))               AS avg_comments,
          platform,
          COUNT(*) AS cnt
        FROM caption_history WHERE user_id=?
        GROUP BY platform
        ORDER BY cnt DESC
    ");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $overall = $pdo->prepare("
        SELECT COUNT(*) AS total_generated,
               SUM(status='used') AS total_used
        FROM caption_history WHERE user_id=?
    ");
    $overall->execute([$userId]);
    $ov = $overall->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['overall'=>$ov, 'by_platform'=>$rows]);
    exit;
}

// ── POST save ────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'save') {
    // Any user can save (auto-called on caption generate); data just won't show unless premium
    $captions = $body['captions'] ?? [];
    $platform = substr($body['platform'] ?? 'instagram', 0, 50);
    $language = substr($body['language'] ?? 'en', 0, 10);

    if (empty($captions)) {
        http_response_code(400); echo json_encode(['error'=>'No captions']); exit;
    }

    $ins = $pdo->prepare("
        INSERT INTO caption_history (user_id, captions, platform, language)
        VALUES (?, ?, ?, ?)
    ");
    $ins->execute([$userId, json_encode($captions), $platform, $language]);
    $newId = $pdo->lastInsertId();

    echo json_encode(['ok'=>true, 'id'=>$newId]);
    exit;
}

// ── POST update ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'update') {
    if (!$isPremium) {
        http_response_code(403); echo json_encode(['error'=>'premium_required']); exit;
    }
    $id     = (int)($body['id'] ?? 0);
    $status = $body['status'] ?? null;

    // Verify ownership
    $chk = $pdo->prepare("SELECT id FROM caption_history WHERE id=? AND user_id=?");
    $chk->execute([$id, $userId]);
    if (!$chk->fetch()) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }

    $sets   = [];
    $params = [];

    if ($status && in_array($status, ['generated','used','skipped'])) {
        $sets[]   = 'status = ?';
        $params[] = $status;
        if ($status === 'used') {
            $sets[]   = 'used_at = NOW()';
        }
    }
    if (isset($body['used_caption'])) {
        $sets[]   = 'used_caption = ?';
        $params[] = substr($body['used_caption'], 0, 2000);
    }
    if (isset($body['likes']))    { $sets[] = 'likes = ?';    $params[] = (int)$body['likes']; }
    if (isset($body['comments'])) { $sets[] = 'comments = ?'; $params[] = (int)$body['comments']; }
    if (isset($body['reach']))    { $sets[] = 'reach = ?';    $params[] = (int)$body['reach']; }
    if (isset($body['perf_note'])){ $sets[] = 'perf_note = ?';$params[] = substr($body['perf_note'],0,255); }

    if (empty($sets)) { echo json_encode(['ok'=>true]); exit; }

    $params[] = $id;
    $upd = $pdo->prepare("UPDATE caption_history SET ".implode(',',$sets)." WHERE id=?");
    $upd->execute($params);

    echo json_encode(['ok'=>true]);
    exit;
}

// ── POST delete ──────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'delete') {
    if (!$isPremium) { http_response_code(403); echo json_encode(['error'=>'premium_required']); exit; }
    $id = (int)($body['id'] ?? 0);
    $del = $pdo->prepare("DELETE FROM caption_history WHERE id=? AND user_id=?");
    $del->execute([$id, $userId]);
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400);
echo json_encode(['error'=>'Unknown action']);
