<?php
/**
 * Quota configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../../runtime.php';

/**
 * Ensure the given mount point in /etc/fstab contains the quota options.
 */
function pmssEnsureQuotaOptions(string $mountPoint, ?array $requiredOptions = null, ?callable $logger = null, ?string $fstabPath = null): void
{
    // #TODO Add hermetic tests that verify fstab line parsing and option
    //       insertion behavior for common edge cases.
    $log = $logger ?: 'logMessage';
    if ($mountPoint === '') {
        return;
    }
    $fstab = $fstabPath ?? '/etc/fstab';
    if (!is_readable($fstab)) {
        $log('[WARN] '.$fstab.' not readable; skipping quota configuration.');
        return;
    }

    $lines = file($fstab, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $log('[WARN] Unable to read '.$fstab.'; skipping quota configuration.');
        return;
    }

    $requiredOptions = $requiredOptions ?? ['usrjquota=aquota.user', 'grpjquota=aquota.group', 'jqfmt=vfsv1'];
    $found = false;
    $changed = false;

    foreach ($lines as $idx => $line) {
        $columns = pmssConfigLineColumns($line, 4);
        if ($columns === [] || $columns[1] !== $mountPoint) {
            continue;
        }

        $found = true;
        $plan = pmssConfigOptionsUpdatePlan($columns[3], $requiredOptions, [], true);
        $missingOptions = $plan['added'];
        if ($missingOptions === []) {
            $log('[SKIP] Quota options already present for '.$mountPoint);
            break;
        }
        $columns[3] = implode(',', $plan['options']);
        $lines[$idx] = implode("\t", $columns);
        $changed = true;
        $log('[WARN] Updated quota options for '.$mountPoint.' (added '.implode(', ', $missingOptions).')');
        break;
    }

    if (!$found) {
        $log('[WARN] Mount point '.$mountPoint.' not found in '.$fstab.'; skipping quota updates.');
        return;
    }

    if ($changed) {
        pmssWriteManagedPathFileWithBackup($fstab, $lines, 'fstab', $log, true);
    }
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
