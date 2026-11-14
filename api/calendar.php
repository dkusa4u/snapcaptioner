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

if (!$isPremium) {
    http_response_code(403);
    echo json_encode(['error'=>'premium_required','message'=>'Content Calendar is a premium feature.']);
    exit;
}

// Auto-create table
$pdo->exec("
CREATE TABLE IF NOT EXISTS content_calendar (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT          NOT NULL,
  title          VARCHAR(255) NOT NULL,
  caption        TEXT         DEFAULT NULL,
  platform       VARCHAR(50)  DEFAULT 'instagram',
  scheduled_date DATE         NOT NULL,
  scheduled_time TIME         DEFAULT '09:00:00',
  status         ENUM('idea','draft','ready','posted') DEFAULT 'idea',
  notes          TEXT         DEFAULT NULL,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cal_user_date (user_id, scheduled_date)
)
");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        // Returns all posts for a given month: ?month=2026-11
        $month = $_GET['month'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
        $start = $month . '-01';
        $end   = date('Y-m-t', strtotime($start));

        $rows = $pdo->prepare("
            SELECT id, title, caption, platform, scheduled_date, scheduled_time,
                   status, notes, created_at
            FROM content_calendar
            WHERE user_id = ? AND scheduled_date BETWEEN ? AND ?
            ORDER BY scheduled_date ASC, scheduled_time ASC
        ");
        $rows->execute([$userId, $start, $end]);
        echo json_encode(['posts' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $row = $pdo->prepare("SELECT * FROM content_calendar WHERE id=? AND user_id=?");
        $row->execute([$id, $userId]);
        $post = $row->fetch(PDO::FETCH_ASSOC);
        if (!$post) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
        echo json_encode(['post' => $post]);
        exit;
    }
}

if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'create') {
        $title    = substr(trim($body['title']    ?? ''), 0, 255);
        $platform = substr(trim($body['platform'] ?? 'instagram'), 0, 50);
        $date     = $body['scheduled_date'] ?? date('Y-m-d');
        $time     = $body['scheduled_time'] ?? '09:00:00';
        $status   = $body['status']   ?? 'idea';
        $caption  = $body['caption']  ?? null;
        $notes    = $body['notes']    ?? null;

        if (!$title) { http_response_code(400); echo json_encode(['error'=>'Title required']); exit; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { http_response_code(400); echo json_encode(['error'=>'Invalid date']); exit; }
        if (!in_array($status, ['idea','draft','ready','posted'])) $status = 'idea';

        $ins = $pdo->prepare("
            INSERT INTO content_calendar (user_id, title, caption, platform, scheduled_date, scheduled_time, status, notes)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $ins->execute([$userId, $title, $caption, $platform, $date, $time, $status, $notes]);
        echo json_encode(['ok'=>true, 'id'=>$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'update') {
        $id = (int)($body['id'] ?? 0);
        $chk = $pdo->prepare("SELECT id FROM content_calendar WHERE id=? AND user_id=?");
        $chk->execute([$id, $userId]);
        if (!$chk->fetch()) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }

        $sets=[]; $params=[];
        $fields = ['title'=>255,'platform'=>50,'caption'=>null,'scheduled_date'=>null,'scheduled_time'=>null,'status'=>null,'notes'=>null];
        foreach ($fields as $f=>$len) {
            if (!isset($body[$f])) continue;
            if ($f==='status' && !in_array($body[$f],['idea','draft','ready','posted'])) continue;
            $sets[]   = "$f = ?";
            $params[] = $len ? substr($body[$f],0,$len) : $body[$f];
        }
        if (empty($sets)) { echo json_encode(['ok'=>true]); exit; }
        $params[] = $id;
        $upd = $pdo->prepare("UPDATE content_calendar SET ".implode(',',$sets)." WHERE id=?");
        $upd->execute($params);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        $del = $pdo->prepare("DELETE FROM content_calendar WHERE id=? AND user_id=?");
        $del->execute([$id, $userId]);
        echo json_encode(['ok'=>true]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error'=>'Unknown action']);
