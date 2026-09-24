<?php
/**
 * Quota configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../../runtime.php';
pmssRequireRelativeFiles(__DIR__, ['../fstab.php', '../managedPath.php']);

/**
 * Ensure the given mount point in /etc/fstab contains the quota options.
 */
function pmssEnsureQuotaOptions(string $mountPoint, ?array $requiredOptions = null, ?callable $logger = null, ?string $fstabPath = null): void
{
    pmssQuotaFstabOptionsApply($mountPoint, $requiredOptions ?? ['usrjquota=aquota.user', 'grpjquota=aquota.group', 'jqfmt=vfsv1'], 'quota', $logger, $fstabPath);
}

/** Apply the quota mount policies through one read/plan/backup flow, retaining their log text. */
function pmssQuotaFstabOptionsApply(string $mountPoint, array $requiredOptions, string $policy, ?callable $logger, ?string $fstabPath): void
{
    $log = $logger ?: 'logMessage';
    if ($mountPoint === '') {
        return;
    }
    $fstab = $fstabPath ?? '/etc/fstab';
    [$context, $missing, $unchanged, $changed] = [
        'quota' => ['quota', 'quota updates', 'Quota options', 'Updated quota options'],
        'journal' => ['journal commit', 'journal commit update', 'Journal commit option', 'Updated journal commit option'],
        'nofail' => ['nofail', 'nofail update', 'nofail option', 'Added nofail option'],
    ][$policy];
    $lines = pmssFstabLinesRead($fstab, $log, $context.' configuration.');
    if ($lines === null) {
        return;
    }

    $replacePrefixedOptions = [];
    foreach ($requiredOptions as $requiredOption) {
        $equalsPosition = strpos($requiredOption, '=');
        if ($equalsPosition !== false) {
            $replacePrefixedOptions[substr($requiredOption, 0, $equalsPosition + 1)] = $requiredOption;
        }
    }
    // nofail adds only its flag; quota and journal updates also drop a lone defaults token.
    $plan = pmssFstabMountOptionsEnsure($lines, $mountPoint, $requiredOptions, [], $policy !== 'nofail', null, $replacePrefixedOptions);
    if ($plan === null) {
        $log('[WARN] Mount point '.$mountPoint.' not found in '.$fstab.'; skipping '.$missing.'.');
        return;
    }

    if (!$plan['changed']) {
        $log('[SKIP] '.$unchanged.' already present for '.$mountPoint);
        return;
    }

    $log('[WARN] '.$changed.' for '.$mountPoint.pmssFstabPlanChangeSuffix($plan));
    pmssWriteManagedPathFileWithBackup($fstab, $lines, 'fstab', $log, true);
}

/**
 * Ensure the given mount point in /etc/fstab carries an ext4 journal commit interval.
 *
 * A longer commit interval (default 60s vs the ext4 default 5s) batches journal commits, easing
 * jbd2 write-convoy contention on shared RAID5 seedbox hosts. `commit=` is remount-able, so
 * pmssConfigureQuotaMount's remount applies it live — no reboot needed. Uses the same
 * backup-protected fstab helpers as pmssEnsureQuotaOptions.
 *
 * NOTE: `data=writeback` is deliberately NOT set here. It trades crash-consistency for speed and
 * requires a reboot to take effect — an operator policy decision, not an automatic default.
 */
function pmssEnsureJournalCommitOption(string $mountPoint, int $seconds = 60, ?callable $logger = null, ?string $fstabPath = null): void
{
    if ($mountPoint === '' || $seconds < 1) {
        return;
    }
    pmssQuotaFstabOptionsApply($mountPoint, ['commit='.$seconds], 'journal', $logger, $fstabPath);
}

/**
 * Ensure the given mount point carries `nofail` so a failed mount at boot does not hang the host.
 *
 * PMSS hosts are remote-managed: a `/home` mount failure should leave the host REACHABLE (SSH up)
 * for recovery, not drop to an interactive emergency prompt that needs console/IPMI. The Phase 5.3
 * pre-reboot checklist expects `nofail` on `/home` for exactly this reason (a host observed in the
 * fleet without it stalled the pre-reboot check). `nofail` is a boot-time option (NOT remount-able,
 * unlike `commit=`), so this only writes fstab — it takes effect on the next boot. Minimal delta:
 * it adds `nofail` and touches no other option.
 */
function pmssEnsureMountNofailOption(string $mountPoint, ?callable $logger = null, ?string $fstabPath = null): void
{
    pmssQuotaFstabOptionsApply($mountPoint, ['nofail'], 'nofail', $logger, $fstabPath);
}

/**
 * Warn if quota state files under the mount point have unexpected names.
 *
 * ext4 journaled quotas expect `aquota.user` and `aquota.group`. Garbage
 * names have been observed in the fleet and usually indicate manual edits
 * or interrupted quota tooling.
 */
function pmssWarnUnexpectedQuotaFiles(string $mountPoint, ?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    if ($mountPoint === '' || !is_dir($mountPoint) || ($entries = @scandir($mountPoint)) === false) {
        return;
    }

    $unexpected = [];
    foreach ($entries as $entry) {
        if (strpos($entry, 'aquota.') !== 0 || $entry === 'aquota.user' || $entry === 'aquota.group') {
            continue;
        }
        $unexpected[] = pmssQuotaEscapePathForLog($entry);
    }

    if ($unexpected === []) {
        return;
    }

    sort($unexpected, SORT_STRING);
    $log('[WARN] Unexpected quota files under '.$mountPoint.': '.implode(', ', $unexpected));
}

/** Escape filesystem paths before writing them to operator-facing logs. */
function pmssQuotaEscapePathForLog(string $path): string
{
    return addcslashes($path, "\0..\37\177..\377");
}

/**
 * Run one quota maintenance command and return exit-code-aware output.
 *
 * @param callable|null $runner Test seam; receives the shell command and returns
 *                              rc/stdout/stderr keys like pmssCommandCapture().
 *
 * @return array{ok:bool,rc:int,output:string}
 */
function pmssQuotaCommandRun(string $command, ?callable $runner = null): array
{
    $command = trim($command);
    if ($command === '') {
        return ['ok' => false, 'rc' => 1, 'output' => ''];
    }

    $result = ($runner ?? 'pmssCommandCapture')($command);
    if (!is_array($result)) {
        return ['ok' => false, 'rc' => 1, 'output' => ''];
    }

    $rcRaw = $result['rc'] ?? 1;
    $rc = is_int($rcRaw) ? $rcRaw : (is_numeric($rcRaw) ? (int) $rcRaw : 1);
    $stdout = is_string($result['stdout'] ?? null) ? $result['stdout'] : '';
    $stderr = is_string($result['stderr'] ?? null) ? $result['stderr'] : '';

    return ['ok' => $rc === 0, 'rc' => $rc, 'output' => $stdout.$stderr];
}

/**
 * Run a quotaFix maintenance command while preserving legacy command output.
 *
 * `quotaon -p` returns the count of enabled quota types, so verification queries
 * can return non-zero for a healthy state and must not be logged as failures.
 *
 * @param callable|null $runner Test seam; receives the shell command and returns
 *                              rc/stdout/stderr keys like pmssCommandCapture().
 * @param callable|null $logger Test seam for logMessage().
 *
 * @return array{ok:bool,rc:int,output:string}
 */
function pmssQuotaFixRunCommand(string $description, string $command, bool $critical, int &$exitCode, bool $isQuery = false, ?callable $runner = null, ?callable $logger = null): array
{
    $log = $logger ?: 'logMessage';
    $log($description);
    $result = pmssQuotaCommandRun($command, $runner);
    if ($result['output'] !== '') {
        echo $result['output'];
    }
    if (!$result['ok']) {
        if (!$isQuery) {
            $log('[quotaFix] WARNING: command failed (rc='.$result['rc'].'): '.$command);
        }
        if ($critical) {
            $exitCode = 1;
        }
    }

    return $result;
}

/**
 * Remove stale quota check files after validating the mount point boundary.
 *
 * quotacheck may leave temporary `aquota*new` files behind after an interrupted
 * run. Only regular files directly under the mount point are eligible here.
 */
function pmssRemoveStaleQuotaCheckFiles(string $mountPoint = '/home', ?callable $logger = null): int
{
    $log = $logger ?: 'logMessage';
    $mountPoint = rtrim(trim($mountPoint), '/');
    if ($mountPoint === '') {
        $mountPoint = '/';
    }

    if ($mountPoint[0] !== '/' || preg_match('/[\r\n\0]/', $mountPoint) === 1) {
        $log('[quotaFix] WARNING: refusing unsafe quota cleanup path: '.pmssQuotaEscapePathForLog($mountPoint));
        return 0;
    }

    $realMountPoint = realpath($mountPoint);
    if ($realMountPoint === false || !is_dir($mountPoint) || is_link($mountPoint) || $realMountPoint !== $mountPoint) {
        $log('[quotaFix] WARNING: refusing quota cleanup outside stable mount point: '.pmssQuotaEscapePathForLog($mountPoint));
        return 0;
    }

    $staleFiles = glob($mountPoint.'/aquota*new');
    if ($staleFiles === false) {
        $log('[quotaFix] WARNING: unable to scan stale quota check files under '.pmssQuotaEscapePathForLog($mountPoint));
        return 0;
    }

    if ($staleFiles === []) {
        $log('[quotaFix] No stale files found');
        return 0;
    }

    $removed = 0;
    foreach ($staleFiles as $stale) {
        if (dirname($stale) !== $mountPoint || !pmssRegularFilePathIsReadable($stale)) {
            $log('[quotaFix] WARNING: skipped unsafe stale quota path: '.pmssQuotaEscapePathForLog($stale));
            continue;
        }

        if (!@unlink($stale)) {
            $log('[quotaFix] WARNING: failed to remove stale file: '.pmssQuotaEscapePathForLog($stale));
            continue;
        }

        $removed++;
        $log('[quotaFix] Removed stale file: '.pmssQuotaEscapePathForLog($stale));
    }

    return $removed;
}
