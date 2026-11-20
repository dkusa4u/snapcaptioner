<?php
/**
 * Diagnostic script — run once, self-deletes.
 * Safe to commit: contains no secrets.
 */
header('Content-Type: text/plain');

$configPath = __DIR__ . '/config.php';
echo "=== SnapCaptioner Diagnostic ===\n";
echo "PHP version: " . PHP_VERSION . "\n";
echo "Script dir:  " . __DIR__ . "\n\n";

echo "--- config.php ---\n";
if (!file_exists($configPath)) {
    echo "NOT FOUND at: $configPath\n";
} else {
    echo "EXISTS\n";
    echo "Readable: " . (is_readable($configPath) ? 'yes' : 'NO') . "\n";
    echo "Writable: " . (is_writable($configPath) ? 'yes' : 'NO') . "\n";
    $perms = substr(sprintf('%o', fileperms($configPath)), -4);
    echo "Perms:    $perms\n";
    echo "Size:     " . filesize($configPath) . " bytes\n\n";

    // Show first 25 lines (no secrets printed — just constant names)
    $lines = file($configPath);
    echo "First 25 lines (values masked):\n";
    foreach (array_slice($lines, 0, 25) as $i => $line) {
        // Mask everything after => or ' that could be a value
        $masked = preg_replace("/(define\s*\(\s*'[^']+'\s*,\s*)'[^']*'/", "$1'***'", $line);
        $masked = preg_replace("/(define\s*\(\s*\"[^\"]+\"\s*,\s*)\"[^\"]*\"/", '$1"***"', $masked);
        echo sprintf("%3d: %s", $i + 1, $masked);
    }
    echo "\n--- Defined constants ---\n";
    $constants = [
        'DB_HOST','DB_NAME','DB_USER','DB_PASS',
        'OPENAI_API_KEY','STRIPE_PUBLISHABLE_KEY','STRIPE_SECRET_KEY',
        'STRIPE_WEBHOOK_SECRET',
        'STRIPE_STARTER_PRICE','STRIPE_PREMIUM_PRICE',
        'STRIPE_PRO_PRICE','STRIPE_TEAMS_PRICE',
    ];
    require_once $configPath;
    foreach ($constants as $c) {
        echo "$c: " . (defined($c) ? 'DEFINED' : 'NOT DEFINED') . "\n";
    }
}

echo "\n--- PHP error_reporting ---\n";
echo "error_reporting: " . error_reporting() . "\n";
echo "display_errors:  " . ini_get('display_errors') . "\n";

@unlink(__FILE__);
echo "\nDONE (script self-deleted)\n";
