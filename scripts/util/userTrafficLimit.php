#!/usr/bin/env php
<?php
/**
 * Configure per-user traffic limits from the command line.
 *
 * Traffic limit semantics:
 * - The limit is a monthly traffic quota stored as an integer GiB.
 * - `scripts/cron/trafficLimits.php` consumes /etc/seedbox/runtime/trafficLimits/<user>.
 * - The user-facing web UI reads /home/<user>/.trafficLimit.
 *
 * Usage:
 *   ./userTrafficLimit.php --user=<username> --limit=<GiB>
 *   ./userTrafficLimit.php --user=<username> --show
 *   ./userTrafficLimit.php --user=<username> --unset
 *   ./userTrafficLimit.php <username> <GiB>
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/trafficLimit.php';

$usage = rtrim(<<<'TEXT'
Usage:
  ./userTrafficLimit.php --user=<username> --limit=<GiB>
  ./userTrafficLimit.php --user=<username> --show
  ./userTrafficLimit.php --user=<username> --unset
  ./userTrafficLimit.php <username> <GiB>

Notes:
  - Limit unit is GiB (monthly quota).
  - Use 0 (or --unset) to remove a limit.
TEXT
);

// Resolve and validate the target before mutating any persisted state; shared helper keeps the missing username contract: "Error: missing username.\n".$usage."\n"
exit(pmssUserTrafficLimitCli($argv ?? ($_SERVER['argv'] ?? []), $usage));
