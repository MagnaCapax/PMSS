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

logMessage('[quotaFix] Starting quota integrity check');

// 1. Report current status before any changes
logMessage('[quotaFix] Current quota state:');
echo shell_exec('repquota -as 2>&1');

// 2. Disable quotas to allow safe recalculation
logMessage('[quotaFix] Disabling quotas for recalculation');
echo shell_exec('quotaoff -av 2>&1');

// 3. Remove any stale/interrupted check files from previous runs
logMessage('[quotaFix] Cleaning stale quota check files');
pmssRemoveStaleQuotaCheckFiles('/home');

// 4. Perform full quota recalculation
// Flags: -a (all filesystems), -v (verbose), -u (user quotas),
//        -g (group quotas), -m (don't remount ro), -n (use first found quota file)
logMessage('[quotaFix] Recalculating quota usage from disk (this may take time on large filesystems)');
$checkResult = shell_exec('quotacheck -avugmn 2>&1');
echo $checkResult;
if ($checkResult === null || strpos($checkResult, 'Cannot') !== false) {
    logMessage('[quotaFix] WARNING: quotacheck may have encountered issues');
    $exitCode = 1;
}

// 5. Brief stabilization pause
usleep(500000);

// 6. Re-enable quotas
logMessage('[quotaFix] Re-enabling quotas');
echo shell_exec('quotaon -av 2>&1');

// 7. Report final status for visual comparison
logMessage('[quotaFix] Final quota state:');
echo shell_exec('repquota -as 2>&1');

logMessage('[quotaFix] Quota integrity check complete');

exit($exitCode);
