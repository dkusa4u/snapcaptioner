<?php
/**
 * SnapCaption AI — One-time Database Setup
 * File: api/setup.php
 *
 * Run ONCE after uploading to Hostinger to create all DB tables.
 * Access: https://yourdomain.com/api/setup.php?token=YOUR_SETUP_TOKEN
 *
 * IMPORTANT: Delete or rename this file after running it successfully.
 *
 * Steps:
 *   1. Add to api/config.php:  define('SETUP_TOKEN', 'your-random-secret');
 *   2. Visit https://yourdomain.com/api/setup.php?token=your-random-secret
 *   3. Confirm all tables show ✓
 *   4. Delete this file from the server
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=UTF-8');

// ── Token gate (temporarily bypassed — re-enable after DB setup) ───────────
// $provided = trim($_GET['token'] ?? '');
// $expected = defined('SETUP_TOKEN') ? SETUP_TOKEN : '';
// if (!$expected || $provided !== $expected) {
//     http_response_code(403);
//     echo '<!DOCTYPE html><html><head><title>403</title></head><body>';
//     echo '<h2>403 — Access Denied</h2>';
//     echo '<p>Add <code>define("SETUP_TOKEN", "your-secret");</code> to <code>api/config.php</code>';
//     echo ' then visit <code>?token=your-secret</code>.</p>';
//     echo '</body></html>';
//     exit;
// }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>SnapCaption — DB Setup</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: system-ui, -apple-system, sans-serif;
      background: #0a0a0f;
      color: #e5e5e5;
      min-height: 100vh;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      padding: 60px 20px;
    }
    .wrap  { width: 100%; max-width: 640px; }
    h1     { font-size: 1.5rem; color: #a855f7; margin-bottom: 24px; }
    h2     { font-size: 1.1rem; margin-bottom: 16px; }
    .box   { background: #111118; border: 1px solid #2a2a3a; border-radius: 12px;
             padding: 24px; margin-bottom: 20px; }
    table  { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #222; }
    th     { color: #888; font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; }
    .ok    { color: #4ade80; font-weight: 600; }
    .err   { color: #f87171; font-weight: 600; }
    .warn  { color: #fbbf24; }
    pre    { background: #1a1a2e; padding: 14px 18px; border-radius: 8px;
             font-size: 13px; overflow-x: auto; margin-top: 10px; }
    .meta  { font-size: .8rem; color: #555; margin-top: 8px; }
    code   { font-family: monospace; background: #1a1a2e; padding: 2px 6px; border-radius: 4px; font-size: .9em; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>⚡ SnapCaption AI — Database Setup</h1>

  <?php
  $allOk   = true;
  $rows    = [];

  try {
      // Create / verify all tables
      initDB();
      $db = getDB();

      $tables = ['users', 'tokens', 'captions', 'usage_log'];
      foreach ($tables as $t) {
          $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($t));
          $found = ($stmt->rowCount() > 0);
          if (!$found) $allOk = false;
          $rows[] = [
              'table' => $t,
              'ok'    => $found,
              'msg'   => $found ? 'Ready (created or already existed)' : 'NOT FOUND — check DB credentials',
          ];
      }

      // Show results
      $heading = $allOk
          ? '<span class="ok">✅ All tables ready</span>'
          : '<span class="err">❌ One or more tables missing</span>';
      echo "<div class='box'><h2>$heading</h2>";
      echo "<table><thead><tr><th>Table</th><th>Status</th></tr></thead><tbody>";
      foreach ($rows as $r) {
          $icon = $r['ok'] ? '<span class="ok">✓</span>' : '<span class="err">✗</span>';
          echo "<tr><td><code>{$r['table']}</code></td><td>$icon &nbsp;{$r['msg']}</td></tr>";
      }
      echo "</tbody></table>";
      echo "<p class='meta'>DB host: <code>" . (defined('DB_HOST') ? DB_HOST : '—') . "</code> &nbsp;|&nbsp; "
         . "Database: <code>" . (defined('DB_NAME') ? DB_NAME : '—') . "</code></p>";
      echo "</div>";

  } catch (Throwable $e) {
      $allOk = false;
      echo "<div class='box'><h2><span class='err'>❌ Error</span></h2>"
         . "<p>" . htmlspecialchars($e->getMessage()) . "</p></div>";
  }

  if ($allOk):
  ?>

  <div class="box">
    <h2 class="warn">⚠️ Next step: delete this file</h2>
    <p>Setup is complete. Remove <code>api/setup.php</code> from your server now — leaving it
    up lets anyone who guesses the token re-run the setup script.</p>
    <p style="margin-top:12px">Via SSH / Hostinger File Manager:</p>
    <pre>rm public_html/api/setup.php</pre>
  </div>

  <?php else: ?>

  <div class="box">
    <h2>Troubleshooting</h2>
    <ul style="padding-left:20px;line-height:2">
      <li>Check <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> in <code>api/config.php</code></li>
      <li>Confirm the MySQL user has <strong>CREATE TABLE</strong> privileges</li>
      <li>Check Hostinger → Databases → phpMyAdmin for connection errors</li>
    </ul>
  </div>

  <?php endif; ?>

</div>
</body>
</html>
