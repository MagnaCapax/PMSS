<?php
/**
 * Systemd user slice setup orchestration for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/logging.php';
require_once dirname(__DIR__).'/runtime/commands.php';
require_once dirname(__DIR__, 2).'/runtime.php';
require_once __DIR__.'/cgroupModeDetect.php';
require_once __DIR__.'/hostResourcesDetect.php';
require_once __DIR__.'/systemdSlicesDropinInstall.php';
require_once __DIR__.'/systemdSlicesRuntimeApply.php';

    /**
     * Install tuned systemd slice overrides when missing.
     */
    function pmssEnsureSystemdSlices(?callable $logger = null): void
    {
        $log = pmssSelectLogger($logger);
        // Avoid touching the host systemd manager in test mode so dev tests stay hermetic.
        $skipSystemctl = (defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true);

        // Drop-in management must target /etc paths only; avoid vendor dirs.
        // Some legacy hosts still carry a vendor drop-in at:
        //   /usr/lib/systemd/system/user-.slice.d/99-pmss.conf
        //   /lib/systemd/system/user-.slice.d/99-pmss.conf
        // which can override PMSS settings (including TasksMax) and can even
        // reappear after dpkg updates. Remove it when found.
        $sawLegacyVendorDropin = false;
        if (!$skipSystemctl) {
            $legacyVendorDropins = [
                '/usr/lib/systemd/system/user-.slice.d/99-pmss.conf',
                '/lib/systemd/system/user-.slice.d/99-pmss.conf',
            ];
            foreach ($legacyVendorDropins as $legacyPath) {
                if (!is_file($legacyPath)) {
                    continue;
                }
                $sawLegacyVendorDropin = true;
                if (@unlink($legacyPath)) {
                    $log('[WARN] Removed legacy vendor systemd drop-in '.$legacyPath);
                } else {
                    $log('[WARN] Unable to remove legacy vendor systemd drop-in '.$legacyPath);
                }
            }
        }

        $mode = pmssCgroupMode();
        $dropDir = pmssResolvePathFromEnv('PMSS_SYSTEMD_USER_SLICE_DIR', '/etc/systemd/system/user-.slice.d');
        $target  = $dropDir.'/15-pmss.conf';
        is_dir($dropDir) || runStep('Creating user-.slice drop-in directory', 'install -d -m 0755 '.escapeshellarg($dropDir));

        // Render template based on cgroup mode
        $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
        $tpl = $mode === 'v2'
            ? $cfgDir.'/template.cgroup.user-slice.v2.conf'
            : $cfgDir.'/template.cgroup.user-slice.v1.conf';

        $tasksMax = pmssSystemdSlicesDropinInstall($tpl, $cfgDir, $mode, $dropDir, $target, $sawLegacyVendorDropin, $log);
        if ($tasksMax === null) {
            return;
        }

        if ($skipSystemctl) {
            pmssLogStatus('SKIP', 'Reloading systemd manager configuration (test mode)', 0);
        } else {
            runStep('Reloading systemd manager configuration', 'systemctl daemon-reload');
        }
        $log(sprintf('Installed %s slice override (mode=%s)', $target, $mode));

        pmssSystemdSlicesRuntimeApply($dropDir, $tasksMax, $skipSystemctl, $log);
    }
