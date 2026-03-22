#!/usr/bin/env php
<?php
/**
 * PMSS product configuration helper (welcome messages only).
 *
 * Usage:
 *   productConfig.php PRODUCT --welcome-message="<html>"
 *   productConfig.php PRODUCT --welcome-message=""
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/lib/runtime.php';

pmssRequireCli();

require_once __DIR__.'/lib/welcomeMessageProductConfig.php';

$usage = "Usage: productConfig.php PRODUCT --welcome-message=<html>\n";
if (($argv[1] ?? '') === '--help' || ($argv[1] ?? '') === '-h') {
    echo $usage;
    exit(0);
}

requireRoot();

$productKey = trim((string) ($argv[1] ?? ''));
if ($productKey === '') {
    fwrite(STDERR, "Error: missing product.\n".$usage);
    exit(2);
}

$welcomeMessage = null;
for ($index = 2; $index < count($argv); $index++) {
    if (strpos((string) $argv[$index], '--welcome-message=') === 0) {
        $welcomeMessage = substr((string) $argv[$index], strlen('--welcome-message='));
    }
}

if ($welcomeMessage === null) {
    fwrite(STDERR, "Error: missing --welcome-message option.\n".$usage);
    exit(2);
}

if (!pmssWelcomeProductMessageSet($productKey, $welcomeMessage)) {
    fwrite(STDERR, "Error: failed to update welcome message config.\n");
    exit(1);
}

if (trim($welcomeMessage) === '') {
    echo "Cleared welcome message for product '{$productKey}'.\n";
} else {
    echo "Updated welcome message for product '{$productKey}'.\n";
}
