#!/usr/bin/env php
<?php
/**
 * Utility script: make Monitoring Rules.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// Configure Ip tables rules for monitoring network traffic usage

require_once '/scripts/lib/network/iptables.php';
require_once '/scripts/lib/network/config.php';
require_once '/scripts/lib/resources/log.php';

$userUids = pmssResourceLogManagedUserUids();
if (!$userUids) exit(0);

// Owner match is required for per-user accounting; skip if unavailable.
if (!networkIptablesOwnerMatchAvailable()) exit(0);

// Multiple networks may be defined, one per line, to mark "local" traffic.
$localnets = networkLoadLocalnets();
echo networkMonitoringRulesRender($userUids, $localnets);
