#!/usr/bin/env php
<?php
/**
 * Remote utility: record this server's own disk-wait figures and its public tier name for the welcome page.
 *
 * The brain (hallinta) queues one call per node, per day: tier ownPsi ownSvcMs unixTs. The welcome page reads the result
 * and compares it with the averages of the public fleet-performance feed (ADR 0227). The arguments come from a queue row
 * and are run as root, so every one is checked against a strict pattern before anything is written.
 *
 * Usage: fleetCompare.php <tier|-> <ownPsi> <ownSvcMs> <unixTs>
 *   tier: the public tier name (letters, digits, hyphen, at most 16) or a single - when the server's group has none;
 *   ownPsi, ownSvcMs: decimals with at most 6 digits and 2 decimals (PSI full as percent of time over the past week,
 *   response time in milliseconds); unixTs: 10 digits.
 *
 * Writes /var/lib/pmss/public/fleetCompare.json (root:root 0644), keys tier (null for -), psi, svcMs, ts.
 * Exit codes: 0 ok; 2 bad arguments (nothing written); 6 not root; 7 unsafe target or write failed.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../../lib/lighttpd/userFileWrite.php';
require_once __DIR__.'/../../lib/runtime/cli.php';

const PMSS_FLEET_COMPARE_PATH = '/var/lib/pmss/public/fleetCompare.json';

/** Parse the four queue arguments; null when any of them is not exactly what the pattern allows. */
function pmssFleetCompareArgsParse(array $args): ?array
{
    if (count($args) !== 4 || !is_string($args[0]) || preg_match('/\A(-|[A-Za-z0-9-]{1,16})\z/', $args[0]) !== 1) return null;
    $numbers = [];
    foreach ([$args[1], $args[2]] as $raw) {
        if (!is_string($raw) || preg_match('/\A\d{1,6}(\.\d{1,2})?\z/', $raw) !== 1) return null;
        $numbers[] = (float) $raw;
    }
    if (!is_string($args[3]) || preg_match('/\A\d{10}\z/', $args[3]) !== 1) return null;
    return ['tier' => $args[0] === '-' ? null : $args[0], 'psi' => $numbers[0], 'svcMs' => $numbers[1], 'ts' => (int) $args[3]];
}

function pmssFleetCompareCli(array $argv, string $path = PMSS_FLEET_COMPARE_PATH): int
{
    $usage = "Usage: fleetCompare.php <tier|-> <ownPsi> <ownSvcMs> <unixTs>\n";
    $payload = pmssFleetCompareArgsParse(array_slice($argv, 1));
    if ($payload === null) return pmssCliReturnWithStderr("Error: arguments must be a tier name or -, two plain numbers and a 10-digit timestamp.\n".$usage, 2);
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) return pmssCliReturnWithStderr("Error: fleetCompare.php must run as root.\n", 6);
    // A symlink at the target or in its path would turn this root write into a write elsewhere.
    if (is_link($path) || !pmssPathTargetIsSafe($path, false, true)) return pmssCliReturnWithStderr("Error: unsafe target {$path}.\n", 7);
    if (!pmssEnsureSafeDir(dirname($path), 0755) || !pmssAtomicJsonFileWrite($path, $payload, 0644)) {
        return pmssCliReturnWithStderr("Error: failed to write {$path}.\n", 7);
    }
    return 0;
}

if (!defined('PMSS_TEST_MODE') || !PMSS_TEST_MODE) exit(pmssFleetCompareCli($argv));
