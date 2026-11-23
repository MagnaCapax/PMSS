<?php
/**
 * Read-only boot-safety detector: a BIOS GRUB upgrade that the running boot predates.
 *
 * A grub-pc upgrade refreshes the modules under /boot/grub and relies on its own grub-install to rewrite
 * core.img in the MBR. When that grub-install fails the node keeps running and hangs at the GRUB banner on
 * its next reboot. update.php never writes the MBR (ADR 0340), so this only reports the latent state.
 *
 * Loaded by systemPrep.php. @license GPL-3.0-only
 */

/** Files above this size are not read: a runaway log must not stall the update. */
const PMSS_BOOT_GRUB_LOG_MAX_BYTES = 16777216;

/**
 * Epoch seconds of every grub-pc / grub-pc-bin package upgrade recorded in the given dpkg logs.
 *
 * Only `upgrade` rows count: they are the ones whose maintainer script runs grub-install. A missing log is
 * normal (the rotated one may not exist). A log that exists but cannot be read, is too large or fails the pattern
 * engine contributes nothing and is counted in $unusable, so the caller can say the result is partial. Times are
 * read in PHP's default timezone, which matches dpkg's local-time stamps on hosts that keep the system and PHP
 * zones aligned (UTC on the fleet).
 *
 * @param array<int,string> $logPaths dpkg log files, any order
 * @param int $unusable receives how many existing logs could not be used
 * @return array<int,int> epoch seconds, ascending
 */
function pmssBootGrubUpgradeTimes(array $logPaths, &$unusable = 0): array
{
    $times = [];
    $unusable = 0;
    foreach ($logPaths as $path) {
        // Invalid selectors are unusable evidence, not filesystem arguments.
        if (!is_string($path) || $path === '' || strpos($path, "\0") !== false) {
            $unusable++;
            continue;
        }
        if (!is_file($path)) {
            continue;
        }
        $size = @filesize($path);
        // Recheck the actual bytes: the log may grow after the size observation.
        $text = ($size === false || $size > PMSS_BOOT_GRUB_LOG_MAX_BYTES)
            ? false : @file_get_contents($path, false, null, 0, PMSS_BOOT_GRUB_LOG_MAX_BYTES + 1);
        if ($text === false || strlen($text) > PMSS_BOOT_GRUB_LOG_MAX_BYTES || strpos($text, "\0") !== false) {
            $unusable++;
            continue;
        }
        // Row shape: "2026-06-21 06:06:49 upgrade grub-pc:amd64 2.06-13+deb12u1 2.06-13+deb12u2".
        $rows = [];
        if (preg_match_all('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) upgrade grub-pc(?:-bin)?(?::\S+)? \S+ \S+$/m', $text, $rows) === false) {
            $unusable++;
            continue;
        }
        foreach ($rows[1] as $stamp) {
            $epoch = strtotime($stamp);
            if ($epoch !== false) {
                $times[] = $epoch;
            }
        }
    }
    sort($times);
    return $times;
}

/**
 * Boot time of the running kernel in epoch seconds, or null when it cannot be read.
 *
 * @param string $procStat path of the kernel statistics file carrying the btime row
 */
function pmssBootGrubBootEpoch(string $procStat): ?int
{
    if ($procStat === '' || strpos($procStat, "\0") !== false) {
        return null;
    }
    $text = is_file($procStat) ? @file_get_contents($procStat) : false;
    if ($text === false || strpos($text, "\0") !== false || preg_match('/^btime\s+(\d+)\s*$/m', $text, $match) !== 1) {
        return null;
    }
    return (int) $match[1];
}

/**
 * Warn when a grub-pc upgrade is newer than the running boot, and change nothing.
 *
 * The reboot that follows such an upgrade is the first to load the new modules with whatever core.img the
 * package's grub-install left behind, so it is the one that can hang. A host that has rebooted since the
 * upgrade has already proved its MBR. Returns 'warn', 'ok' or 'skip' (no usable evidence) for callers and tests.
 *
 * @param callable|null $logger message sink, defaults to logMessage
 * @param array<int,string>|null $logPaths dpkg logs to scan, defaults to the current and previous log
 * @param string|null $procStat kernel statistics path, defaults to /proc/stat
 */
function pmssBootGrubStaleCoreImgCheck(?callable $logger = null, ?array $logPaths = null, ?string $procStat = null): string
{
    $log = $logger ?: 'logMessage';
    $bootEpoch = pmssBootGrubBootEpoch($procStat ?? '/proc/stat');
    if ($bootEpoch === null) {
        $log('[SKIP] GRUB boot-safety check: boot time unreadable');
        return 'skip';
    }
    $unusable = 0;
    $times = pmssBootGrubUpgradeTimes($logPaths ?? ['/var/log/dpkg.log.1', '/var/log/dpkg.log'], $unusable);
    if ($unusable > 0) {
        // Fail visibly, not open: a log that could not be read means a quiet result below is only partial.
        $log('[WARN] GRUB boot-safety check: '.$unusable.' dpkg log file(s) unreadable or too large; the result is partial');
    }
    $pending = array_values(array_filter($times, function ($epoch) use ($bootEpoch) {
        return $epoch >= $bootEpoch;
    }));
    if ($pending === []) {
        $log('[SKIP] GRUB boot-safety check: no grub-pc upgrade since the running boot');
        return 'ok';
    }
    $log('[WARN] GRUB boot-safety check: grub-pc was upgraded '.count($pending).' time(s) since this host booted (last '
        .gmdate('Y-m-d H:i:s', (int) end($pending)).' UTC, boot '.gmdate('Y-m-d H:i:s', $bootEpoch).' UTC). '
        .'The MBR core.img is untested against the new modules; before the next reboot run grub-install on every boot '
        .'disk and update-grub. update.php does not write the MBR.');
    return 'warn';
}
