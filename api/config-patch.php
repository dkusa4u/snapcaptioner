<?php
/**
 * config-patch.php — ONE-TIME run to add Stripe constants to config.php
 * Visit this URL, submit the form with your Stripe keys.
 * Script SELF-DELETES after a successful write.
 */

$configPath = __DIR__ . '/config.php';
$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pk  = trim($_POST['pk']  ?? '');
    $sk  = trim($_POST['sk']  ?? '');

    if (!$pk || !$sk) {
        $message = 'ERROR: Both keys are required.';
    } elseif (!file_exists($configPath)) {
        $message = "ERROR: config.php not found at: $configPath";
    } elseif (!is_writable($configPath)) {
        $message = "ERROR: config.php is not writable";
    } else {
        $contents = file_get_contents($configPath);

        if (strpos($contents, 'STRIPE_PUBLISHABLE_KEY') !== false) {
            $message = 'NOTICE: Stripe constants already present — no changes made.';
        } else {
            $stripeBlock  = "\n// Stripe configuration\n";
            $stripeBlock .= "define('STRIPE_PUBLISHABLE_KEY', '" . addslashes($pk) . "');\n";
            $stripeBlock .= "define('STRIPE_SECRET_KEY',      '" . addslashes($sk) . "');\n";
            $stripeBlock .= "define('STRIPE_WEBHOOK_SECRET',  'whsec_ZBLiRRhDKsmNMRsaF2jNtKHQgnX31a35');\n";
            $stripeBlock .= "define('STRIPE_STARTER_PRICE',   'price_1UdhaOA7kmlgA49Mc4NotKJA');\n";
            $stripeBlock .= "define('STRIPE_PREMIUM_PRICE',   'price_1UdhaeA7kmlgA49MFlBTrZ06');\n";
            $stripeBlock .= "define('STRIPE_PRO_PRICE',       'price_1UdhanA7kmlgA49MWPUfxhJa');\n";
            $stripeBlock .= "define('STRIPE_TEAMS_PRICE',     'price_1UdhauA7kmlgA49MFiWiMP2z');\n";

            $contents = rtrim($contents);
            if (substr($contents, -2) === '?>') {
                $contents = rtrim(substr($contents, 0, -2)) . "\n" . $stripeBlock . "\n?>";
            } else {
                $contents .= "\n" . $stripeBlock;
            }

            if (file_put_contents($configPath, $contents) !== false) {
                $success = true;
                $message  = "SUCCESS: 7 Stripe constants written to config.php\n\n";
                // Verify
                require_once $configPath;
                foreach (['STRIPE_PUBLISHABLE_KEY','STRIPE_SECRET_KEY','STRIPE_WEBHOOK_SECRET',
                          'STRIPE_STARTER_PRICE','STRIPE_PREMIUM_PRICE','STRIPE_PRO_PRICE','STRIPE_TEAMS_PRICE'] as $c) {
                    $message .= "  $c: " . (defined($c) ? '✓ DEFINED' : '✗ MISSING') . "\n";
                }
                // Self-delete
                @unlink(__FILE__);
                $message .= "\nScript self-deleted. config.php is ready.";
            } else {
                $message = 'ERROR: file_put_contents failed.';
            }
        }
    }
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>SnapCaptioner — Config Patch</title>
<style>
body{font-family:monospace;max-width:600px;margin:40px auto;padding:20px;background:#111;color:#eee}
h2{color:#4ade80}
input{width:100%;padding:8px;margin:6px 0 16px;background:#222;color:#eee;border:1px solid #444;font-family:monospace;font-size:12px}
button{background:#4ade80;color:#000;padding:10px 24px;border:none;cursor:pointer;font-weight:bold}
pre{background:#1a1a1a;padding:12px;white-space:pre-wrap;color:<?= $success ? '#4ade80' : '#f87171' ?>}
</style></head><body>
<h2>SnapCaptioner — Stripe Config Patch</h2>
<?php if ($message): ?>
<pre><?= htmlspecialchars($message) ?></pre>
<?php endif; ?>
<?php if (!$success): ?>
<form method="POST">
  <label>Stripe Publishable Key (pk_live_...)</label>
  <input name="pk" type="text" autocomplete="off" placeholder="pk_live_...">
  <label>Stripe Secret Key (rk_live_... or sk_live_...)</label>
  <input name="sk" type="text" autocomplete="off" placeholder="rk_live_... or sk_live_...">
  <button type="submit">Write to config.php →</button>
</form>
<?php else: ?>
<p style="color:#4ade80">Done. This page no longer exists.</p>
<?php endif; ?>
</body></html>
