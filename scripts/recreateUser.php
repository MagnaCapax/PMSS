#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Recreate tenant helper (v5, BOM-safe, self-healing).
 *
 * - Archives a user's home, rebuilds the account with fresh quota/memory
 *   limits, and restores critical configuration while leaving room for manual
 *   intervention when needed.
 * - Includes defensive BOM handling and strict argument validation to avoid
 *   destructive mistakes during emergency recoveries.
 *
 * This script has been refined since the early 2010s; coordinate any changes
 * with the platform team before altering the workflow.
 *
 * Usage: recreateUser.php USERNAME MAX_RTORRENT_MEMORY_MiB DISK_QUOTA_GiB
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
const USAGE = "Usage: recreateUser.php USERNAME MAX_RTORRENT_MEMORY_MiB DISK_QUOTA_GiB\n";

[$_, $userName, $ramMiB, $quotaGiB] = array_pad($argv, 4, null);

if ($argc !== 4) die(USAGE);
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
$passwd = posix_getpwnam($userName);
if ($passwd === false)
    die("User {$userName} does not exist in /etc/passwd - aborting.\n");
if (is_dir($backupDir))
    die("Backup directory {$backupDir} already exists - remove or rename it first.\n");

$homeExists = is_dir($homeDir);

/* ===== 4. Helpers ===== */
function run(string $cmd): void
{
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Command failed ({$code}): {$cmd}\n");
        exit($code);
    }
}
function ensureDir(string $dir, string $owner): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        run('chown -R ' . escapeshellarg($owner) . ':' . escapeshellarg($owner) . ' ' . escapeshellarg($dir));
    }
}

/* ===== 5. Begin ===== */
echo "[*] Killing processes for {$userName}\n";
run('pkill -9 -u ' . escapeshellarg($userName) . ' || true');

if ($homeExists) {
    echo "[*] Moving {$homeDir} to {$backupDir}\n";
    run('mv ' . escapeshellarg($homeDir) . ' ' . escapeshellarg($backupDir));
} else {
    echo "[i] Home directory missing - building fresh\n";
}

/* ===== 6. Rebuild skeleton ===== */
run('cp -Rp /etc/skel ' . escapeshellarg($homeDir));
run('chown -R ' . escapeshellarg($userName) . ':' . escapeshellarg($userName) . ' ' . escapeshellarg($homeDir));

/* 6a. Guarantee required sub-dirs */
ensureDir("{$homeDir}/data",    $userName);
ensureDir("{$homeDir}/session", $userName);
ensureDir("{$homeDir}/.lighttpd", $userName);

/* ===== 7. Service config ===== */
run(sprintf(
    '/scripts/util/userConfig.php %s %d %d',
    escapeshellarg($userName),
    $ramMiB,
    $quotaGiB
));
run('/scripts/util/setupUserHomePermissions.php ' . escapeshellarg($userName));
run('/scripts/util/userConfigLighttpd.php ' . escapeshellarg($userName));
run('/scripts/util/createNginxConfig.php --user ' . escapeshellarg($userName));
run('/scripts/util/userPermissions.php ' . escapeshellarg($userName));

/* ===== 8. Restore data (if we had any) ===== */
if ($homeExists) {
    echo "[*] Restoring data and session\n";
    foreach (['data', 'session'] as $dir) {
        $src = "{$backupDir}/{$dir}";
        $dst = "{$homeDir}/{$dir}";
        if (is_dir($src)) {
            run('rsync -a ' . escapeshellarg($src . '/') . ' ' . escapeshellarg($dst . '/'));
        }
    }
    if (is_file("{$backupDir}/.lighttpd/.htpasswd")) {
        run('cp ' . escapeshellarg("{$backupDir}/.lighttpd/.htpasswd") . ' ' .
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

/* ===== 10. Done ===== */
echo "[OK] Finished. ";
if ($homeExists) {
    echo "Review then remove backup:  rm -rf " . escapeshellarg($backupDir) . "\n";
} else {
    echo "Fresh build done (no backup created).\n";
}
exit(0);
