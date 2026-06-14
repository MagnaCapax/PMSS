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
    $log = $logger ?: 'logMessage';
    if ($mountPoint === '') {
        return;
    }
    $fstab = $fstabPath ?? '/etc/fstab';
    $lines = pmssFstabLinesRead($fstab, $log, 'quota configuration.');
    if ($lines === null) {
        return;
    }

    $requiredOptions = $requiredOptions ?? ['usrjquota=aquota.user', 'grpjquota=aquota.group', 'jqfmt=vfsv1'];
    $replacePrefixedOptions = [];
    foreach ($requiredOptions as $requiredOption) {
        $equalsPosition = strpos($requiredOption, '=');
        if ($equalsPosition !== false) {
            $replacePrefixedOptions[substr($requiredOption, 0, $equalsPosition + 1)] = $requiredOption;
        }
    }
    $plan = pmssFstabMountOptionsEnsure($lines, $mountPoint, $requiredOptions, [], true, null, $replacePrefixedOptions);
    if ($plan === null) {
        $log('[WARN] Mount point '.$mountPoint.' not found in '.$fstab.'; skipping quota updates.');
        return;
    }

    if (!$plan['changed']) {
        $log('[SKIP] Quota options already present for '.$mountPoint);
        return;
    }

    $log('[WARN] Updated quota options for '.$mountPoint.pmssFstabPlanChangeSuffix($plan));
    pmssWriteManagedPathFileWithBackup($fstab, $lines, 'fstab', $log, true);
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
        $unexpected[] = addcslashes($entry, "\0..\37\177..\377");
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

    if ($runner === null) {
        $runner = function (string $cmd): array {
            return pmssCommandCapture($cmd);
        };
    }

    $result = $runner($command);
    if (!is_array($result)) {
        return ['ok' => false, 'rc' => 1, 'output' => ''];
    }

    $rcRaw = $result['rc'] ?? 1;
    $rc = is_int($rcRaw) ? $rcRaw : (is_numeric($rcRaw) ? (int) $rcRaw : 1);
    $stdout = isset($result['stdout']) && is_string($result['stdout']) ? $result['stdout'] : '';
    $stderr = isset($result['stderr']) && is_string($result['stderr']) ? $result['stderr'] : '';

    return ['ok' => $rc === 0, 'rc' => $rc, 'output' => $stdout.$stderr];
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
        if (dirname($stale) !== $mountPoint || is_link($stale) || !is_file($stale)) {
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
