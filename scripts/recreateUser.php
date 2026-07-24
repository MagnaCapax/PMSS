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

function pmssRequireSafeRecreateUserPath(string $path, string $label): void
{
    if (is_link($path)) {
        fwrite(STDERR, "Refusing to operate on symlinked {$label} path: {$path}\n");
        exit(1);
    }

    if (file_exists($path) && !is_dir($path)) {
        fwrite(STDERR, "Refusing to operate on non-directory {$label} path: {$path}\n");
        exit(1);
    }
}

/* ===== 3. Pre-flight ===== */
$passwd = pmssUserAccountLookup($userName);
if ($passwd === null)
    die("User {$userName} does not exist in /etc/passwd - aborting.\n");
pmssRequireSafeRecreateUserPath($homeDir, 'home');
pmssRequireSafeRecreateUserPath($backupDir, 'backup');

$homeExists = is_dir($homeDir);
if ($homeExists) {
    $realHome = realpath($homeDir);
    if ($realHome === false || $realHome !== $homeDir) {
        fwrite(STDERR, "Refusing to operate on unexpected home path: {$realHome}\n");
        exit(1);
    }
}

// Handle a leftover backup-<user> from a PRIOR rebuild. Policy (2026-07-23): the backup
// persists until terminateUser reclaims it or the NEXT recreateUser supersedes it - it no
// longer blocks a rebuild. We NEVER delete it up-front: a failed prior rebuild can leave
// the customer's ONLY real data here while the current home is an empty skel (which is
// content-indistinguishable from a legitimately near-empty home). So when we have a real
// home to back up, set the prior backup aside and reclaim it at the END (section 11), only
// after the new backup is safely created. When home is missing, leave the prior backup
// untouched (possible sole copy) - a fresh build creates no backup, so there is no collision.
$supersededBackup = null;
if (file_exists($backupDir)) {
    if ($homeExists) {
        $supersededBackup = $backupDir . '.superseded';
        pmssRequireSafeRecreateUserPath($supersededBackup, 'superseded-backup');
        if (file_exists($supersededBackup)) {
            // .trafficData/.trafficDataLocal are immutable (chattr +i, PMSS #161); clear the immutable
            // attr recursively before rm or it fails "Operation not permitted" (cf. terminateUser #176,
            // userTransfer #283). GH PMSS#725. Failing here is fail-safe (before rebuild — live account untouched).
            pmssRunOrExit('chattr -R -i ' . escapeshellarg($supersededBackup) . ' 2>/dev/null; rm -rf ' . escapeshellarg($supersededBackup));
        }
        echo "[*] Setting aside prior backup {$backupDir} -> {$supersededBackup}\n";
        pmssRunOrExit('mv ' . escapeshellarg($backupDir) . ' ' . escapeshellarg($supersededBackup));
    } else {
        echo "[i] Home missing but prior backup {$backupDir} present - leaving it untouched (possible sole copy).\n";
    }
}

function ensureDir(string $dir, string $owner): void
{
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            fwrite(STDERR, "Unable to create required directory: {$dir}\n");
            exit(1);
        }
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
pmssRequireSafeRecreateUserPath($homeDir, 'home');
if (!is_dir($homeDir)) {
    fwrite(STDERR, "Validation failed: homeDir missing after skeleton copy\n");
    exit(1);
}
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
$stat = @stat($homeDir);
if (!is_array($stat)) {
    fwrite(STDERR, "Validation failed: unable to stat homeDir\n");
    exit(1);
}
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

/* ===== 11. Reclaim the superseded prior backup (only after the new backup exists) ===== */
// The current home has now been safely moved into {$backupDir} and the rebuild has passed
// the ownership-sanity check above, so the prior backup we set aside is truly superseded
// and can be reclaimed. $supersededBackup is only ever set when $homeExists was true (a
// fresh backup was created), so this never deletes a possible sole copy.
if ($supersededBackup !== null && is_dir($supersededBackup)) {
    echo "[*] Reclaiming superseded prior backup {$supersededBackup}\n";
    // .trafficData/.trafficDataLocal are immutable (chattr +i, PMSS #161); clear before rm (cf. terminateUser #176). GH PMSS#725.
    // This runs AFTER a successful rebuild — a cleanup failure here must NOT fail the tool (the account is already rebuilt),
    // so it is non-fatal (exec, not pmssRunOrExit). A lingering .superseded is routine janitorial, not a rebuild failure.
    exec('chattr -R -i ' . escapeshellarg($supersededBackup) . ' 2>/dev/null; rm -rf ' . escapeshellarg($supersededBackup) . ' 2>&1', $reclaimOut, $reclaimRc);
    if ($reclaimRc !== 0) {
        fwrite(STDERR, "[!] Non-fatal: could not fully reclaim {$supersededBackup} (rc={$reclaimRc}); rebuild already succeeded, manual cleanup may be needed.\n");
    }
}

/* ===== 12. Done ===== */
echo "[OK] Finished. ";
if ($homeExists) {
    echo "Rebuilt. Backup at {$backupDir} persists (auto-reclaimed by terminateUser or the next rebuild).\n";
} else {
    echo "Fresh build done (no backup created).\n";
}
exit(0);
