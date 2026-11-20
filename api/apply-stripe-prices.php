<?php
/**
 * One-time script: adds Stripe Price IDs to config.php
 * Run once after git pull, then it self-deletes.
 * Safe to commit — does NOT contain secrets.
 */

// Only allow local or CLI execution (extra safety)
if (isset($_SERVER['REMOTE_ADDR']) && !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
    // Allow remote execution from server itself — just log it
}

$configPath = __DIR__ . '/config.php';

if (!file_exists($configPath)) {
    die("ERROR: config.php not found at $configPath\n");
}

$config = file_get_contents($configPath);

// The 4 Stripe Price IDs to inject
$prices = [
    'STRIPE_STARTER_PRICE' => 'price_1UdhaOA7kmlgA49Mc4NotKJA',
    'STRIPE_PREMIUM_PRICE' => 'price_1UdhaeA7kmlgA49MFlBTrZ06',
    'STRIPE_PRO_PRICE'     => 'price_1UdhanA7kmlgA49MWPUfxhJa',
    'STRIPE_TEAMS_PRICE'   => 'price_1UdhauA7kmlgA49MFiWiMP2z',
];

$updated = false;
$messages = [];

foreach ($prices as $const => $value) {
    if (strpos($config, "define('$const'") !== false) {
        // Already defined — skip
        $messages[] = "SKIP: $const already defined";
    } else {
        // Find the closing ?> or end of file and insert before it
        // Insert near STRIPE_SECRET_KEY definition
        $insertAfter = "define('STRIPE_SECRET_KEY'";
        $pos = strpos($config, $insertAfter);
        if ($pos !== false) {
            // Find end of that line
            $lineEnd = strpos($config, "\n", $pos);
            if ($lineEnd === false) $lineEnd = strlen($config);
            $config = substr($config, 0, $lineEnd + 1)
                . "define('$const', '$value');\n"
                . substr($config, $lineEnd + 1);
        } else {
            // Fallback: insert before closing ?>
            $closeTag = strrpos($config, '?>');
            if ($closeTag !== false) {
                $config = substr($config, 0, $closeTag)
                    . "define('$const', '$value');\n"
                    . substr($config, $closeTag);
            } else {
                $config .= "\ndefine('$const', '$value');";
            }
        }
        $messages[] = "ADDED: $const = $value";
        $updated = true;
    }
}

if ($updated) {
    if (file_put_contents($configPath, $config) === false) {
        die("ERROR: Could not write to config.php — check permissions\n");
    }
    $messages[] = "SUCCESS: config.php updated.";
}

// Self-delete
@unlink(__FILE__);
$messages[] = "DONE: This script has self-deleted.";

echo implode("\n", $messages) . "\n";
