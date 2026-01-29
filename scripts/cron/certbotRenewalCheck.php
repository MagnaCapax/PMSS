#!/usr/bin/env php
<?php
/**
 * Certbot renewal log check.
 *
 * Reads the Certbot log and emits a concise status line. Intended to run under
 * root cron and surface renewal failures early without performing renewals.
 *
 * Exit codes:
 * - 0: OK / NOOP
 * - 1: FAIL
 * - 2: UNKNOWN (log missing/unparseable)
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/certbotRenewal.php';

$logPath = '/var/log/letsencrypt/letsencrypt.log';
if (isset($argv[1]) && is_string($argv[1]) && $argv[1] !== '') {
    // Allow operators to pass an explicit path when debugging.
    $logPath = $argv[1];
}

$summary = pmssCertbotRenewalSummaryFromFile($logPath);
$status = $summary['status'] ?? 'unknown';
$eventTs = $summary['event_ts'] ?? null;
$event = $summary['event'] ?? '';

$now = time();
$ageHours = null;
if (is_int($eventTs) && $eventTs > 0) {
    $ageHours = ($now - $eventTs) / 3600;
}

// Certbot renewal typically runs at least twice a day; warn when the last
// renewal summary marker is stale to catch broken cron/renew flows.
$stale = ($ageHours !== null && $ageHours > 48);

$prefix = '[UNKNOWN]';
$exit = 2;
if ($status === 'ok' || $status === 'noop') {
    $prefix = $stale ? '[WARN]' : '[OK]';
    $exit = $stale ? 2 : 0;
} elseif ($status === 'fail') {
    $prefix = '[ERR]';
    $exit = 1;
}

$tsText = is_int($eventTs) ? date('Y-m-d H:i:s', $eventTs) : 'unknown';
$ageText = $ageHours !== null ? sprintf('%.1fh', $ageHours) : 'unknown';

echo sprintf('%s certbot renew status=%s last=%s age=%s :: %s', $prefix, $status, $tsText, $ageText, $event).PHP_EOL;

if ($status === 'fail' && isset($summary['error_excerpt']) && is_string($summary['error_excerpt']) && trim($summary['error_excerpt']) !== '') {
    echo "[ERR] excerpt:\n".$summary['error_excerpt'].PHP_EOL;
}

exit($exit);

