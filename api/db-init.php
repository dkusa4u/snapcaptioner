<?php
/**
 * db-init.php — ONE-TIME run to create missing DB tables (password_reset_tokens)
 * Access: https://snapcaptioner.com/api/db-init.php?key=SnapInit2026
 * Self-deletes after success.
 */
if (($_GET['key'] ?? '') !== 'SnapInit2026') {
    http_response_code(403); echo '403'; exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
header('Content-Type: text/plain');

try {
    initDB();
    $db  = getDB();
    $out = "initDB() ran OK\n\n";
    foreach (['users','tokens','captions','usage_log','social_connections','password_reset_tokens'] as $t) {
        $found = ($db->query("SHOW TABLES LIKE " . $db->quote($t))->rowCount() > 0);
        $out  .= ($found ? '✓ ' : '✗ ') . $t . "\n";
    }
    echo $out . "\nDone. Script deleted.";
    @unlink(__FILE__);
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}
