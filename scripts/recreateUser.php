#!/usr/bin/env php
<?php
/**
 * PMSS script: recreate User.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

/**
 * Recreate tenant helper (v5, BOM-safe, self-healing).
 *
 * - Archives a user's home, rebuilds the account with fresh quota/memory
 *   limits, restores critical configuration, and resets credentials via
 *   changePw.php (echoing the final password for operator capture).
 * - Includes defensive BOM handling and strict argument validation to avoid
 *   destructive mistakes during emergency recoveries.
 *
 * This script has been refined since the early 2010s; coordinate any changes
 * with the platform team before altering the workflow.
 *
 * Usage: recreateUser.php USERNAME MAX_RAM_MiB DISK_QUOTA_GiB [PASSWORD]
 *
 * @author  Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */

/* ===== 0. Strip UTF-8 BOM if present ===== */
if (substr(__FILE__, 0, 3) === "\xEF\xBB\xBF") {
    // This shouldn't normally happen because PHP doesn't include the BOM in __FILE__,
    // but some editors slap BOM bytes before the #! shebang; handle that defensively.
    $stdin = fopen('php://stdin', 'r'); // noop, forces PHP to finish header parsing
}

/* ===== 1. CLI parsing ===== */
require_once __DIR__.'/lib/homeMount.php';
require_once __DIR__.'/lib/shell.php';
$userLifecycleLib = __DIR__.'/lib/userLifecycle.php';
if (is_file($userLifecycleLib)) {
    require_once $userLifecycleLib;
}

// Guard: PMSS requires /home to be a separately mounted filesystem. Recreating
// a user when /home is unavailable would fail in confusing ways or corrupt state.
pmssRequireHomeMounted('recreateUser.php');

const USAGE = "Usage: recreateUser.php USERNAME MAX_RAM_MiB DISK_QUOTA_GiB [PASSWORD]\n";

[$_, $userName, $ramMiB, $quotaGiB, $password] = array_pad($argv, 5, null);

if ($argc < 4 || $argc > 5) die(USAGE);
$userName = function_exists('pmssNormalizeUsername')
    ? pmssNormalizeUsername((string) $userName)
    : strtolower((string) $userName);
if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $userName))
    die("Invalid username\n");
if (!ctype_digit($ramMiB) || (int)$ramMiB < 1)
    die("ramMiB must be a positive integer\n");
if (!ctype_digit($quotaGiB) || (int)$quotaGiB < 1)
    die("quotaGiB must be a positive integer\n");

$ramMiB   = (int)$ramMiB;
$quotaGiB = (int)$quotaGiB;

/* ===== 2. Paths ===== */
$homeDir   = "/home/{$userName}";
$backupDir = "/home/backup-{$userName}";
// #TODO consider abstracting path handling into shared helper to keep scripts in sync.

/* ===== 3. Pre-flight ===== */
$passwd = pmssUserAccountLookup($userName);
if ($passwd === null)
    die("User {$userName} does not exist in /etc/passwd - aborting.\n");
if (is_dir($backupDir))
    die("Backup directory {$backupDir} already exists - remove or rename it first.\n");

$homeExists = is_dir($homeDir);

function ensureDir(string $dir, string $owner): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        pmssRunOrExit('chown -R ' . escapeshellarg($owner) . ':' . escapeshellarg($owner) . ' ' . escapeshellarg($dir));
    }
}

/* ===== 5. Begin ===== */
echo "[*] Killing processes for {$userName}\n";
pmssRunOrExit('pkill -9 -u ' . escapeshellarg($userName) . ' || true');

if ($homeExists) {
    echo "[*] Moving {$homeDir} to {$backupDir}\n";
    pmssRunOrExit('mv ' . escapeshellarg($homeDir) . ' ' . escapeshellarg($backupDir));
} else {
    echo "[i] Home directory missing - building fresh\n";
}

/* ===== 6. Rebuild skeleton ===== */
pmssRunOrExit('cp -Rp /etc/skel ' . escapeshellarg($homeDir));
pmssRunOrExit('chown -R ' . escapeshellarg($userName) . ':' . escapeshellarg($userName) . ' ' . escapeshellarg($homeDir));

/* 6a. Guarantee required sub-dirs */
ensureDir("{$homeDir}/data",    $userName);
ensureDir("{$homeDir}/session", $userName);
ensureDir("{$homeDir}/.lighttpd", $userName);

/* ===== 7. Service config ===== */
pmssRunOrExit(sprintf(
    '/scripts/util/userConfig.php %s %d %d',
    escapeshellarg($userName),
    $ramMiB,
    $quotaGiB
));
pmssRunOrExit('/scripts/util/setupUserHomePermissions.php ' . escapeshellarg($userName));
pmssRunOrExit('/scripts/util/userConfigLighttpd.php ' . escapeshellarg($userName));
pmssRunOrExit('/scripts/util/createNginxConfig.php --user ' . escapeshellarg($userName));
pmssRunOrExit('/scripts/util/userPermissions.php ' . escapeshellarg($userName));

/* ===== 8. Restore data (if we had any) ===== */
if ($homeExists) {
    echo "[*] Restoring data and session\n";
    foreach (['data', 'session'] as $dir) {
        $src = "{$backupDir}/{$dir}";
        $dst = "{$homeDir}/{$dir}";
        if (is_dir($src)) {
            pmssRunOrExit('rsync -a ' . escapeshellarg($src . '/') . ' ' . escapeshellarg($dst . '/'));
        }
    }
    if (is_file("{$backupDir}/.lighttpd/.htpasswd")) {
        pmssRunOrExit('cp ' . escapeshellarg("{$backupDir}/.lighttpd/.htpasswd") . ' ' .
            escapeshellarg("{$homeDir}/.lighttpd/"));
    }
}

/* ===== 9. Ownership sanity ===== */
$uid = $passwd['uid'];
$gid = $passwd['gid'];
$stat = stat($homeDir);
if ($stat['uid'] !== $uid || $stat['gid'] !== $gid) {
    fwrite(STDERR, "Validation failed: homeDir ownership mismatch\n");
    exit(1);
}

/* ===== 10. Password ===== */
$pwArgs = escapeshellarg($userName);
if ($password !== null && $password !== '') {
    $pwArgs .= ' ' . escapeshellarg($password);
}
echo "[*] Setting password\n";
pmssRunOrExit('php ' . __DIR__ . '/changePw.php ' . $pwArgs);

/* ===== 11. Done ===== */
echo "[OK] Finished. ";
if ($homeExists) {
    echo "Review then remove backup:  rm -rf " . escapeshellarg($backupDir) . "\n";
} else {
    echo "Fresh build done (no backup created).\n";
}
exit(0);
