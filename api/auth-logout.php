<?php
/**
 * SnapCaption AI — POST /api/auth-logout.php
 * Revokes the current bearer token
 */

require_once __DIR__ . '/db.php';
cors();

$user = tokenAuth();
if (!$user) json_out(['success' => true]); // already logged out — idempotent

$headers = getallheaders();
preg_match('/^Bearer\s+(\S+)$/i', $headers['Authorization'] ?? $headers['authorization'] ?? '', $m);
$token = $m[1] ?? '';

if ($token) {
    try {
        getDB()->prepare('DELETE FROM tokens WHERE token = ?')->execute([$token]);
    } catch (PDOException $e) { /* ignore */ }
}

json_out(['success' => true]);
