#!/usr/bin/env php
<?php
/**
 * Trim oversized or aged per-user lighttpd access logs in place (size cap and 30-day retention cap, ADR 0337).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/user/selection.php';
require_once __DIR__.'/../lib/lighttpd/accessLog.php';

[$debug] = pmssCliArgvDebugSplit($argv ?? null);
$logger = new Logger(__FILE__);
$trimmed = 0;
$thresholdBytes = PMSS_LIGHTTPD_ACCESS_LOG_THRESHOLD_BYTES;

foreach (pmssManagedHomeUsersList() as $thisUser) {
    $logPath = "/home/{$thisUser}/.lighttpd/access.log";
    $result = pmssLighttpdAccessLogTrimFile($logPath, $thresholdBytes);
    if (($result['status'] ?? '') === 'trimmed') {
        $trimmed++;
        $logger->msg(($result['reason'] ?? '') === 'age'
            ? "Trimmed aged lighttpd access log for {$thisUser} (oldest entry past the retention cap, {$result['sizeBefore']} bytes)"
            : "Trimmed oversized lighttpd access log for {$thisUser} ({$result['sizeBefore']} bytes)");
        continue;
    }
    if (($result['status'] ?? '') === 'error') {
        $logger->msg("WARN: Unable to trim lighttpd access log for {$thisUser}: {$result['reason']}");
        continue;
    }
    if ($debug && ($result['reason'] ?? '') === 'multiple_links') {
        $logger->msg("SKIP: refusing to truncate multiply-linked lighttpd access log for {$thisUser}");
    }
}

if ($trimmed === 0 && $debug) {
    $logger->msg('No oversized per-user lighttpd access logs found.');
}
