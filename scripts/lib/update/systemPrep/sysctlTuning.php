<?php
/**
 * Hardware-aware sysctl profile helpers for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/sysctlProfile.php';
require_once __DIR__.'/sysctlSettings.php';
require_once __DIR__.'/sysctlSummary.php';

/** Refresh one managed sysctl-adjacent file and report whether it changed. */
function pmssSysctlManagedContentRefresh(string $path, string $content, string $label, callable $log, int $mode = 0644): array
{
    $warnLog = '[WARN] Unable to write '.$label.' at '.$path;
    if (($existing = @file_get_contents($path)) !== false && trim($existing) === trim($content)) {
        // An unchanged symlink or unsafe parent is not a converged managed file.
        if (!pmssManagedPathIsSafe($path, $label, $log)) {
            return [false, false];
        }
        $log('[SKIP] '.ucfirst($label).' already present and up to date');
        return [true, true];
    }

    if (!pmssDirEnsureExists(dirname($path), 0755)) {
        $log($warnLog);
        return [false, false];
    }

    return [false, pmssWriteManagedPathFile($path, $content, $label, $log, null, null, $mode, $warnLog)];
}

/**
 * Recreate the PMSS-owned hardware-aware sysctl baseline.
 */
function pmssEnsureLegacySysctlBaseline(?callable $logger = null, ?string $targetOverride = null, bool $reload = true, ?string $modulesLoadOverride = null): void
{
    $log             = $logger ?: 'logMessage';
    $target          = $targetOverride ?? '/etc/sysctl.d/99-pmss.conf';
    $modulesLoadPath = $modulesLoadOverride ?? '/etc/modules-load.d/pmss-bbr.conf';
    $overridePath    = pmssResolvePathFromEnv('PMSS_SYSCTL_OVERRIDES_PATH', '/etc/sysctl.d/90-pmss-overrides.conf');
    // Persist TCP BBR module loading across reboots.
    $modulesContent = "# PMSS: enable TCP BBR\ntcp_bbr\n";

    // /sys block tuning is handled by the boot-time tuning service; sysctl only covers /proc/sys.
    $profile = pmssSysctlProfileDetect();
    $overrideKeys = pmssSysctlOverridesParse($overridePath);
    $groupedSettings = pmssSysctlSettingsFilterOverrides(pmssSysctlSettingsBuild($profile), $overrideKeys);
    $changes = pmssSysctlChangesDescribe(pmssSysctlFileParse($target), $groupedSettings);

    [$sysctlUpToDate, $sysctlWriteOk] = pmssSysctlManagedContentRefresh(
        $target,
        pmssSysctlConfigRender($groupedSettings).PHP_EOL,
        'legacy sysctl defaults',
        $log
    );

    pmssSysctlSummaryWrite($logger, $profile, $groupedSettings, $overrideKeys, $changes);

    [$modulesUpToDate, $modulesWriteOk] = pmssSysctlManagedContentRefresh(
        $modulesLoadPath,
        $modulesContent,
        'TCP BBR modules-load configuration',
        $log
    );
    if (!$modulesUpToDate && $modulesWriteOk) {
        $log('Refreshed TCP BBR modules-load configuration at '.$modulesLoadPath);
    }

    if ($sysctlUpToDate || !$sysctlWriteOk) {
        return;
    }

    $reload ? runStep('Reloading sysctl configuration', 'sysctl --system') : $log('[SKIP] sysctl reload disabled');
    $log('Refreshed legacy sysctl defaults at '.$target);
}
