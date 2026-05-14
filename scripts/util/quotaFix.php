#!/usr/bin/env php
<?php
/**
 * Quota integrity recalculation utility.
 *
 * Linux kernel quotas can drift out of sync over time, after crashes, or when
 * files are modified outside normal I/O paths. This utility forces a full
 * recalculation of quota usage to ensure enforcement matches actual disk state.
 *
 * Invocation contexts:
 *   - install.sh: Run during initial installation to establish baseline
 *   - update-step2: Run during updates to correct any accumulated drift
 *   - Manual: Operators can invoke directly when quota reports look wrong
 *   - Cron: May be scheduled for periodic recalculation on busy hosts
 *
 * Operations performed:
 *   1. Report current quota state (repquota -as)
 *   2. Disable quotas temporarily (quotaoff -av)
 *   3. Remove stale check files (/home/aquota*new)
 *   4. Recalculate quota usage from disk (quotacheck -avugmn)
 *   5. Re-enable quotas (quotaon -av)
 *   6. Report final state for comparison
 *
 * Exit codes:
 *   0 - Success (quota recalculation completed)
 *   1 - Failure (not root or quota commands failed)
 *
 * @since 2011-03-27
 * @see https://wiki.pulsedmedia.com/index.php/PM_Software_Stack_Changelog_2011-2014
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/update/services/quota.php';

requireRoot();

$exitCode = 0;

/**
 * Run a quota maintenance command while preserving legacy command output.
 *
 * @return array{ok:bool,rc:int,output:string}
 */
function pmssQuotaFixRunCommand(string $description, string $command, bool $critical, int &$exitCode): array
{
    logMessage($description);
    $result = pmssQuotaCommandRun($command);
    if ($result['output'] !== '') {
        echo $result['output'];
    }
    if (!$result['ok']) {
        logMessage('[quotaFix] WARNING: command failed (rc='.$result['rc'].'): '.$command);
        if ($critical) {
            $exitCode = 1;
        }
    }

    return $result;
}

logMessage('[quotaFix] Starting quota integrity check');

// 1. Report current status before any changes
pmssQuotaFixRunCommand('[quotaFix] Current quota state:', 'repquota -as', false, $exitCode);

// 2. Disable quotas to allow safe recalculation
$quotaOffResult = pmssQuotaFixRunCommand('[quotaFix] Disabling quotas for recalculation', 'quotaoff -av', true, $exitCode);

if ($quotaOffResult['ok']) {
    // 3. Remove any stale/interrupted check files from previous runs
    logMessage('[quotaFix] Cleaning stale quota check files');
    pmssRemoveStaleQuotaCheckFiles('/home');

    // 4. Perform full quota recalculation
    // Flags: -a (all filesystems), -v (verbose), -u (user quotas),
    //        -g (group quotas), -m (don't remount ro), -n (use first found quota file)
    $checkResult = pmssQuotaFixRunCommand(
        '[quotaFix] Recalculating quota usage from disk (this may take time on large filesystems)',
        'quotacheck -avugmn',
        true,
        $exitCode
    );
    if (strpos($checkResult['output'], 'Cannot') !== false) {
        logMessage('[quotaFix] WARNING: quotacheck may have encountered issues');
        $exitCode = 1;
    }
} else {
    logMessage('[quotaFix] WARNING: skipping quotacheck because quotaoff failed');
    $exitCode = 1;
}

// 5. Brief stabilization pause
usleep(500000);

// 6. Re-enable quotas
pmssQuotaFixRunCommand('[quotaFix] Re-enabling quotas', 'quotaon -av', true, $exitCode);

// 7. Report final status for visual comparison
pmssQuotaFixRunCommand('[quotaFix] Final quota state:', 'repquota -as', false, $exitCode);

logMessage('[quotaFix] Quota integrity check complete');

exit($exitCode);
