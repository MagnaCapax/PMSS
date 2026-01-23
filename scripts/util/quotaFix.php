#!/usr/bin/env php
<?php
/**
 * Quota integrity fixer.
 *
 * Linux kernel quotas are not infallible and can drift out of sync over time or
 * after crashes. This utility forces a full recalculation of quota usage to ensure
 * limits are enforcing the correct values.
 *
 * History:
 *  - First introduced: 2011-03-27
 *  - Reference: https://wiki.pulsedmedia.com/index.php/PM_Software_Stack_Changelog_2011-2014
 *
 * @author Aleksi Ursin
 * @copyright 2011-2026 Pulsed Media
 */

echo date('Y-m-d H:i:s') . ': Checking quota and fixing' . "\n";

// Compatibility fix for systems where quota was compiled from source.
// Ensures /usr/bin/quota points to the source-installed binary if present.
if (file_exists('/usr/local/bin/quota')) {
    shell_exec('rm -rf /usr/bin/quota');
    shell_exec('ln -s /usr/local/bin/quota /usr/bin/quota');
}

// 1. Report current status
echo shell_exec('repquota -as');

// 2. Turn off quota to allow check
echo shell_exec('quotaoff -av');

// 3. Clean up any stale/interrupted check files
echo shell_exec('rm -rf /home/aquota*new');

// 4. Perform the check (force, verbose, user/group, no-remount)
echo shell_exec('quotacheck -avugmn');

// 5. Stabilize
sleep(1);

// 6. Re-enable quota
echo shell_exec('quotaon -av');

// 7. Report final status for visual comparison
echo shell_exec('repquota -as');