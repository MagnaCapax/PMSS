<?php
/**
 * Systemd user slice setup orchestration for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../managedPath.php';
require_once dirname(__DIR__, 2).'/cgroup/policy.php';
require_once dirname(__DIR__).'/../runtime.php';
require_once dirname(__DIR__, 2).'/user/userCgroupSliceDropins.php';

pmssRequireRelativeFiles(__DIR__, ['systemdSliceSizing.php', 'systemdSliceIo.php', 'systemdSliceDropins.php', 'systemdSliceRuntime.php']);

/**
 * Install tuned systemd slice overrides when missing.
 */
function pmssEnsureSystemdSlices(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    // Avoid touching the host systemd manager in test mode so dev tests stay hermetic.
    $skipSystemctl = pmssTestModeEnabled();

    // Repair orphaned as well as active UIDs before any later manager reload.
    if (!$skipSystemctl) {
        pmssUserCgroupSliceDropinsRepair($log, static function (): int {
            return runStep('Reloading systemd after legacy user slice repair', 'systemctl daemon-reload');
        });
    }

    // Drop-in management must target /etc paths only; avoid vendor dirs.
    // Some legacy hosts still carry a vendor drop-in at:
    //   /usr/lib/systemd/system/user-.slice.d/99-pmss.conf
    //   /lib/systemd/system/user-.slice.d/99-pmss.conf
    // which can override PMSS settings (including TasksMax) and can even
    // reappear after dpkg updates. Remove it when found.
    $sawLegacyVendorDropin = false;
    foreach ([
        '/usr/lib/systemd/system/user-.slice.d/99-pmss.conf',
        '/lib/systemd/system/user-.slice.d/99-pmss.conf',
    ] as $legacyPath) {
        if ($skipSystemctl || !is_file($legacyPath)) {
            continue;
        }
        $sawLegacyVendorDropin = true;
        $log((@unlink($legacyPath)
            ? '[WARN] Removed legacy vendor systemd drop-in '
            : '[WARN] Unable to remove legacy vendor systemd drop-in ').$legacyPath);
    }

    $mode = pmssCgroupMode();
    $dropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_SLICE_DIR', '/etc/systemd/system/user-.slice.d');
    $target  = $dropDir.'/15-pmss.conf';

    // Render template based on cgroup mode
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $tpl = $cfgDir.'/template.cgroup.user-slice.'.($mode === 'v2' ? 'v2' : 'v1').'.conf';

    if (!file_exists($tpl)) {
        $log('[WARN] Slice template missing: '.$tpl);
        return;
    }

    $totalMiB = pmssTotalMemMiB();
    $policy = pmssCgroupPolicyLoad($cfgDir);
    $repl = pmssSystemdSliceSizing($policy, $totalMiB, pmssTotalCpuThreads());
    $tasksMax = (int) $repl['%%USER_CGROUP_TASKS_MAX%%'];
    $repl['%%USER_CGROUP_IO_DEVICE_LATENCY%%'] = pmssSystemdSliceIoLatency($policy, $mode, $log);
    $template = is_file($tpl) ? @file_get_contents($tpl) : false;
    if ($template === false) {
        // A non-file or unreadable template must not replace a working drop-in.
        $log('[WARN] Unable to read slice template: '.$tpl);
        return;
    }
    $raw = strtr($template, $repl).pmssSystemdSliceIoMounts($policy, $mode, $log);

    pmssRefreshManagedPathFile($target, $raw, 'systemd drop-in', $log, [
        'writeFailureMessage' => '[WARN] Failed to atomically replace user-.slice drop-in '.$target,
    ], $sliceReady);
    if (!$sliceReady) {
        return;
    }

    pmssSystemdSliceShadowInstall($dropDir, $tasksMax, $sawLegacyVendorDropin, $log);

    pmssSystemdUserManagerDropinsInstall($policy, $log);

    if ($skipSystemctl) {
        pmssLogStatus('SKIP', 'Reloading systemd manager configuration (test mode)', 0);
    } else {
        runStep('Reloading systemd manager configuration', 'systemctl daemon-reload');
    }
    $log(sprintf('Installed %s slice override (mode=%s)', $target, $mode));

    // Ensure root (uid 0) slice is not limited: create user-0 specific
    // override setting infinity/large limits.
    $rootDir = dirname($dropDir).'/user-0.slice.d';
    // Use a suffix that sorts after legacy 99-pmss.conf drop-ins so root
    // remains unlimited even when a stale vendor file exists.
    $rootDrop = $rootDir.'/99-zz-pmss-unlimited.conf';
    // Keep the legacy protection if its replacement cannot be installed.
    pmssRefreshManagedPathFile(
        $rootDrop,
        "[Slice]\nMemoryHigh=infinity\nMemoryMax=infinity\nTasksMax=infinity\n",
        'systemd root slice drop-in',
        $log,
        ['writeFailureMessage' => '[WARN] Failed to install root slice drop-in '.$rootDrop],
        $rootDropReady
    );
    if ($rootDropReady) {
        @unlink($rootDir.'/99-pmss-unlimited.conf');
    }
    pmssSystemdSliceRuntimeRefresh($tasksMax, $skipSystemctl, $log);
}
