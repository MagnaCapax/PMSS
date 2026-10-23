#!/usr/bin/env php
<?php
/**
 * Periodic host-level systemd/logind control-channel recovery.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/systemdControlChannel.php';

if (!pmssTestModeEnabled()) {
    requireRoot();
}
if (!pmssSystemdRuntimeAvailable()) {
    exit(0);
}

$lock = pmssCronLockAcquire('systemdControlChannelCheck');
$log = static function (string $message): void { echo pmssLogFormatTimestampedLine($message, 'c', ' systemd-control-channel: '); };
exit(pmssSystemdControlChannelCheck('pmssCommandCapture', 'runStep', $log));
