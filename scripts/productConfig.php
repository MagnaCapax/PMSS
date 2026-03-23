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
require_once __DIR__.'/lib/cli/optionParser.php';

pmssRequireCli();

require_once __DIR__.'/lib/welcomeMessage.php';

$usage = "Usage: productConfig.php PRODUCT --welcome-message=<html>\n";
$parsed = pmssParseCliTokens($argv ?? ($_SERVER['argv'] ?? []), ['welcome-message']);
if (pmssCliOption($parsed, 'help', 'h')) {
    echo $usage;
    exit(0);
}

requireRoot();

$productKey = trim((string) ($parsed['arguments'][0] ?? ''));
if ($productKey === '') {
    fwrite(STDERR, "Error: missing product.\n".$usage);
    exit(2);
}

$welcomeMessage = pmssCliOption($parsed, 'welcome-message');
$welcomeMessage = ($welcomeMessage === true || $welcomeMessage === null) ? null : (string) $welcomeMessage;

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
