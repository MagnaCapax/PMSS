#!/usr/bin/env php
<?php
/**
 * PMSS support request command.
 *
 * User-facing entry point for submitting a support request from SSH without
 * privileged access. The command captures a local diagnostics snapshot, saves
 * it under the caller's home directory, and submits the same snapshot by email.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/support/request.php';

/**
 * Print command usage help.
 */
function pmssSupportUsagePrint(): void
{
    fwrite(STDERR, "Usage: support <message>\n");
}

$args = $argv ?? $_SERVER['argv'] ?? [];
array_shift($args);

if ($args === ['--help'] || $args === ['-h']) {
    pmssSupportUsagePrint();
    exit(0);
}

if (count($args) < 1) {
    pmssSupportUsagePrint();
    exit(1);
}

try {
    $result = pmssSupportRequestSubmit(implode(' ', $args));
    fwrite(STDOUT, "Ticket submitted! You'll receive a response via email.\n");
    fwrite(STDOUT, 'Snapshot saved to '.$result['snapshotPath']."\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Support request failed: '.$exception->getMessage()."\n");
    exit(1);
}
