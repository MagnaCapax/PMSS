#!/usr/bin/env php
<?php
/**
 * Safe root-filesystem cleanup — reclaim REGENERABLE cruft ONLY, when / is near-full.
 *
 * On a shared PMSS host whose ROOT filesystem approaches 100% from accumulated cruft
 * (root full for days while /home has ample free space and the arrays are healthy —
 * an OS-root exhaustion, not a hardware failure), free space WITHOUT a blanket rm.
 *
 * Whitelist-ONLY, hardcoded regenerable classes:
 *   - apt package cache            (apt-get clean)
 *   - journald journal over a cap  (journalctl --vacuum-size)
 *   - ROTATED logs (never live)    (/var/log/**  *.gz / *.N / *.N.gz / *.old, older than N days)
 *   - dist-upgrade scratch         (/var/tmp/mkinitramfs_*)
 *   - old atop logs                (/var/log/atop/atop_* older than N days)
 *
 * NEVER touches /home, /var/www, customer data, /etc, /root, /boot, live logs, or any
 * unrecognized/served file. The hardcoded whitelist + a realpath deny/allow gate IS the
 * safety boundary (POLA-correct). Dry-run by DEFAULT; --apply frees and logs every byte.
 *
 * NOT a live-log-storm tool: a filling LIVE syslog/kern.log (e.g. a runaway process
 * OOM-looping into the log) is fixed by stopping the source, not by this cleanup.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/log.php';
require_once __DIR__.'/../lib/runtime/cli.php';

// ─────────────────────────────────────────────────────────────────────────────
// Pure safety gate (unit-tested by PmssSafeRootCleanupTest) — no side effects.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Absolute prefixes a deletable FILE must realpath-resolve under (the file-unlink gate).
 * NOTE: /var/tmp is deliberately NOT here — dist-upgrade scratch is handled by its own
 * dedicated prefix predicate (pmssSafeRootCleanupIsDistUpgradeTmp) + rm -rf, never this
 * per-file gate, so the gate cannot over-allow arbitrary /var/tmp files.
 */
function pmssSafeRootCleanupAllowedPrefixes(): array
{
    return [
        '/var/cache/apt/archives/',
        '/var/lib/apt/lists/partial/',
        '/var/log/',
    ];
}

/** Absolute prefixes that are NEVER touched (defense in depth; wins over allow). */
function pmssSafeRootCleanupDenyPrefixes(): array
{
    return [
        '/home/', '/var/www/', '/etc/', '/root/', '/boot/',
        '/dev/', '/proc/', '/sys/', '/var/lib/mysql/', '/var/spool/',
    ];
}

/** Live log basenames that must NEVER be deleted — only their ROTATED siblings. */
function pmssSafeRootCleanupLiveLogBasenames(): array
{
    return [
        'syslog', 'kern.log', 'messages', 'auth.log', 'daemon.log', 'user.log',
        'debug', 'mail.log', 'cron.log', 'dpkg.log', 'faillog', 'lastlog',
        'wtmp', 'btmp', 'alternatives.log', 'bootstrap.log',
    ];
}

/** A rotated/regenerable log? *.gz, *.N, *.N.gz, *.old — never a live basename. */
function pmssSafeRootCleanupIsRotatedLog(string $path): bool
{
    $base = basename($path);
    if (in_array($base, pmssSafeRootCleanupLiveLogBasenames(), true)) {
        return false;
    }
    return (bool) preg_match('/(?:\.\d+)(?:\.gz)?$|\.gz$|\.old$/', $base);
}

/**
 * THE safety boundary (PURE — no filesystem access, unit-tested directly).
 * Input MUST be an absolute, already-resolved path. deny-prefix → allow-prefix →
 * for /var/log require ROTATED (or the atop_* dated-log class). deny wins over allow.
 */
function pmssSafeRootCleanupPathIsWhitelisted(string $real): bool
{
    if ($real === '' || $real[0] !== '/') {
        return false;
    }
    foreach (pmssSafeRootCleanupDenyPrefixes() as $deny) {
        if (strncmp($real, $deny, strlen($deny)) === 0) {
            return false;
        }
    }
    $allowed = false;
    foreach (pmssSafeRootCleanupAllowedPrefixes() as $allow) {
        if (strncmp($real, $allow, strlen($allow)) === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        return false;
    }
    // Logs under /var/log must be ROTATED (never a live log), EXCEPT atop_* which is
    // a distinct dated-log class.
    if (strncmp($real, '/var/log/', 9) === 0) {
        if (strncmp($real, '/var/log/atop/atop_', 19) === 0) {
            return true;
        }
        return pmssSafeRootCleanupIsRotatedLog($real);
    }
    return true;
}

/**
 * May this exact path be unlinked as regenerable cruft? realpath (must exist + be a
 * regular file) → the pure whitelist predicate. This is the gate every unlink passes.
 */
function pmssSafeRootCleanupIsDeletableFile(string $path): bool
{
    $real = @realpath($path);
    if ($real === false || !is_file($real)) {
        return false;
    }
    return pmssSafeRootCleanupPathIsWhitelisted($real);
}

/** A /var/tmp/mkinitramfs_* scratch dir/file (dist-upgrade leftover)? */
function pmssSafeRootCleanupIsDistUpgradeTmp(string $path): bool
{
    $real = @realpath($path);
    if ($real === false) {
        return false;
    }
    return strncmp($real, '/var/tmp/mkinitramfs_', 21) === 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Collection (pure-ish: read-only fs scans returning candidate lists + sizes).
// ─────────────────────────────────────────────────────────────────────────────

/** Recursively collect rotated logs under /var/log older than $olderThanDays. */
function pmssSafeRootCleanupCollectRotatedLogs(int $olderThanDays, int $now): array
{
    $out = [];
    $root = '/var/log';
    if (!is_dir($root)) {
        return $out;
    }
    $cutoff = $now - ($olderThanDays * 86400);
    // SKIP_DOTS only — RecursiveDirectoryIterator does NOT descend into symlinked
    // directories by default, so a /var/log symlink cannot escape the tree; and the
    // per-file realpath+deny-prefix gate is the final backstop regardless.
    // CATCH_GET_CHILD: a permission-denied subdir (e.g. /var/log/private, 0700) must be
    // SKIPPED, not throw — otherwise one unreadable dir aborts the whole cleanup.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
        RecursiveIteratorIterator::CATCH_GET_CHILD
    );
    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $path = $fileInfo->getPathname();
        // atop handled by its own class; skip here to avoid double-count.
        if (strncmp($path, '/var/log/atop/', 14) === 0) {
            continue;
        }
        if (!pmssSafeRootCleanupIsRotatedLog($path)) {
            continue;
        }
        if ($fileInfo->getMTime() > $cutoff) {
            continue;
        }
        if (!pmssSafeRootCleanupIsDeletableFile($path)) {
            continue;
        }
        $out[$path] = $fileInfo->getSize();
    }
    return $out;
}

/** Collect old atop logs (/var/log/atop/atop_* older than N days). */
function pmssSafeRootCleanupCollectAtopLogs(int $olderThanDays, int $now): array
{
    $out = [];
    $cutoff = $now - ($olderThanDays * 86400);
    foreach (glob('/var/log/atop/atop_*') ?: [] as $path) {
        if (!is_file($path) || filemtime($path) > $cutoff) {
            continue;
        }
        if (strncmp((string) realpath($path), '/var/log/atop/atop_', 19) !== 0) {
            continue;
        }
        $out[$path] = filesize($path);
    }
    return $out;
}

/** Collect dist-upgrade /var/tmp/mkinitramfs_* scratch (dirs and files). */
function pmssSafeRootCleanupCollectDistUpgradeTmp(): array
{
    $out = [];
    foreach (glob('/var/tmp/mkinitramfs_*') ?: [] as $path) {
        if (!pmssSafeRootCleanupIsDistUpgradeTmp($path)) {
            continue;
        }
        $out[$path] = pmssSafeRootCleanupPathBytes($path);
    }
    return $out;
}

/** Bytes on disk for a file or directory (du -sb; bounded to a single path). */
function pmssSafeRootCleanupPathBytes(string $path): int
{
    if (is_file($path)) {
        return (int) filesize($path);
    }
    if (!is_dir($path)) {
        return 0;
    }
    $out = @shell_exec('du -sb ' . escapeshellarg($path) . ' 2>/dev/null');
    return (int) trim(explode("\t", (string) $out)[0]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Main
// ─────────────────────────────────────────────────────────────────────────────

function pmssSafeRootCleanupMain(array $argv): int
{
    $help = "\nSafe root-filesystem cleanup — reclaim REGENERABLE cruft ONLY (whitelist).\n"
        . "Usage: safeRootCleanup.php [--apply] [--min-pct N] [--log-days N] [--journal-mb N] [--force] [--json PATH] [--quiet]\n\n"
        . "  --apply         Actually free space (DEFAULT is dry-run: report only).\n"
        . "  --min-pct N     In --apply, refuse unless root use%% >= N (default 90). --force overrides.\n"
        . "  --log-days N    Delete rotated/atop logs older than N days (default 3).\n"
        . "  --journal-mb N  journald vacuum target size in MB (default 200).\n"
        . "  --force         Run --apply even below --min-pct (still whitelist-only).\n"
        . "  --json PATH     Per-run JSONL audit (default /var/log/pmss/safe-root-cleanup.jsonl).\n"
        . "  --quiet         Suppress the human summary (cron-friendly).\n\n"
        . "NEVER touches /home, /var/www, customer data, /etc, /root, /boot, or live logs.\n"
        . "Not a live-log-storm tool — a filling live syslog/kern.log is fixed by killing the source.\n\n";

    $parsed = pmssParseCliTokens($argv, ['min-pct', 'log-days', 'journal-mb', 'json']);
    if (pmssCliHelpTextEmitIfRequested($parsed, $help)) {
        return 0;
    }

    $apply     = pmssCliOptionPresent($parsed, 'apply');
    $force     = pmssCliOptionPresent($parsed, 'force');
    $quiet     = pmssCliOptionPresent($parsed, 'quiet');
    $minPct    = pmssCliOptionInt($parsed, 'min-pct', null, 90);
    $logDays   = max(1, pmssCliOptionInt($parsed, 'log-days', null, 3));
    $journalMb = max(50, pmssCliOptionInt($parsed, 'journal-mb', null, 200));
    $jsonPath  = pmssCliOptionString($parsed, 'json', null, '/var/log/pmss/safe-root-cleanup.jsonl');

    $now      = time();
    $rootFree = (int) @disk_free_space('/');
    $rootTot  = (int) @disk_total_space('/');
    $rootUsePct = $rootTot > 0 ? (int) round((($rootTot - $rootFree) / $rootTot) * 100) : 0;

    if ($apply && !$force && $rootUsePct < $minPct) {
        if (!$quiet) {
            echo "Root use {$rootUsePct}% < --min-pct {$minPct}%: nothing to do (use --force to override).\n";
        }
        return 0;
    }

    // ── Collect candidates (read-only) ──
    $rotated = pmssSafeRootCleanupCollectRotatedLogs($logDays, $now);
    $atop    = pmssSafeRootCleanupCollectAtopLogs($logDays, $now);
    $tmp     = pmssSafeRootCleanupCollectDistUpgradeTmp();
    $aptBytes     = pmssSafeRootCleanupPathBytes('/var/cache/apt/archives');
    $journalBytes = pmssSafeRootCleanupPathBytes('/var/log/journal');

    $classes = [
        'apt_cache'      => ['bytes' => $aptBytes,                'files' => null],
        'journald'       => ['bytes' => max(0, $journalBytes - ($journalMb * 1048576)), 'files' => null],
        'rotated_logs'   => ['bytes' => array_sum($rotated),     'files' => $rotated],
        'atop_logs'      => ['bytes' => array_sum($atop),        'files' => $atop],
        'distupgrade_tmp'=> ['bytes' => array_sum($tmp),         'files' => $tmp],
    ];
    $estTotal = 0;
    foreach ($classes as $c) {
        $estTotal += (int) $c['bytes'];
    }

    if (!$apply) {
        if (!$quiet) {
            echo "DRY-RUN — root use {$rootUsePct}% (" . pmssSafeRootCleanupHuman($rootFree) . " free). Freeable estimate:\n";
            foreach ($classes as $name => $c) {
                echo sprintf("  %-16s %10s\n", $name, pmssSafeRootCleanupHuman((int) $c['bytes']));
            }
            echo sprintf("  %-16s %10s\n", 'TOTAL', pmssSafeRootCleanupHuman($estTotal));
            echo "Run with --apply to reclaim (whitelist-only).\n";
        }
        return 0;
    }

    // ── APPLY: free each class, measuring actual bytes reclaimed ──
    $freed = ['apt_cache' => 0, 'journald' => 0, 'rotated_logs' => 0, 'atop_logs' => 0, 'distupgrade_tmp' => 0];

    // apt cache — canonical safe command.
    $aptBefore = pmssSafeRootCleanupPathBytes('/var/cache/apt/archives');
    @shell_exec('apt-get clean 2>/dev/null');
    $freed['apt_cache'] = max(0, $aptBefore - pmssSafeRootCleanupPathBytes('/var/cache/apt/archives'));

    // journald — canonical vacuum.
    $jBefore = pmssSafeRootCleanupPathBytes('/var/log/journal');
    @shell_exec('journalctl --vacuum-size=' . escapeshellarg($journalMb . 'M') . ' 2>/dev/null');
    $freed['journald'] = max(0, $jBefore - pmssSafeRootCleanupPathBytes('/var/log/journal'));

    // rotated logs — per-file unlink through the safety gate.
    foreach ($rotated as $path => $size) {
        if (pmssSafeRootCleanupIsDeletableFile($path) && @unlink($path)) {
            $freed['rotated_logs'] += (int) $size;
        }
    }
    // atop logs — validated by its class predicate + gate.
    foreach ($atop as $path => $size) {
        if (pmssSafeRootCleanupIsDeletableFile($path) && @unlink($path)) {
            $freed['atop_logs'] += (int) $size;
        }
    }
    // dist-upgrade tmp — rm -rf each mkinitramfs_* (re-verified prefix).
    foreach ($tmp as $path => $size) {
        if (pmssSafeRootCleanupIsDistUpgradeTmp($path)) {
            @shell_exec('rm -rf ' . escapeshellarg($path) . ' 2>/dev/null');
            if (!file_exists($path)) {
                $freed['distupgrade_tmp'] += (int) $size;
            }
        }
    }

    $freedTotal = array_sum($freed);
    $rootFreeAfter = (int) @disk_free_space('/');
    $rootUsePctAfter = $rootTot > 0 ? (int) round((($rootTot - $rootFreeAfter) / $rootTot) * 100) : 0;

    // Audit: JSONL + the operator server-op log.
    $entry = [
        'ts' => date('c'),
        'tool' => 'safeRootCleanup',
        'host' => gethostname(),
        'root_use_pct_before' => $rootUsePct,
        'root_use_pct_after' => $rootUsePctAfter,
        'freed_bytes' => $freed,
        'freed_total' => $freedTotal,
        'rotated_files_removed' => count($rotated),
        'atop_files_removed' => count($atop),
        'log_days' => $logDays,
        'journal_mb' => $journalMb,
    ];
    $logDirError = null;
    if (pmssLogWriteDirectoryPrepare(dirname($jsonPath), 0755, $logDirError)
        && pmssLogWritePathIsSafe($jsonPath)) {
        pmssJsonLineAppend($jsonPath, $entry);
    }
    pmssLogAppendTimestampedLine(
        '/root/sysadmin.agentic.log',
        sprintf(
            'safeRootCleanup: freed %s (root %d%%->%d%%; apt=%s journald=%s rotated=%s/%df atop=%s/%df tmp=%s)',
            pmssSafeRootCleanupHuman($freedTotal), $rootUsePct, $rootUsePctAfter,
            pmssSafeRootCleanupHuman($freed['apt_cache']),
            pmssSafeRootCleanupHuman($freed['journald']),
            pmssSafeRootCleanupHuman($freed['rotated_logs']), count($rotated),
            pmssSafeRootCleanupHuman($freed['atop_logs']), count($atop),
            pmssSafeRootCleanupHuman($freed['distupgrade_tmp'])
        )
    );

    if (!$quiet) {
        echo "Reclaimed " . pmssSafeRootCleanupHuman($freedTotal)
            . " — root {$rootUsePct}% -> {$rootUsePctAfter}%.\n";
        foreach ($freed as $name => $b) {
            echo sprintf("  %-16s %10s\n", $name, pmssSafeRootCleanupHuman((int) $b));
        }
    }
    return 0;
}

/** Compact human size. */
function pmssSafeRootCleanupHuman(int $bytes): string
{
    $u = ['B', 'K', 'M', 'G', 'T'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($u) - 1) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) $bytes : sprintf('%.1f', $n)) . $u[$i];
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssSafeRootCleanupMain');
