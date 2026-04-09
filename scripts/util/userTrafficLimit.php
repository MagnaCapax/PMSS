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
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/trafficLimit.php';

// Resolve and validate the target before mutating any persisted state.
exit(pmssUserTrafficLimitCli($argv ?? ($_SERVER['argv'] ?? [])));
