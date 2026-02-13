<?php
/**
 * Legacy sysctl baseline renderer for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/logging.php';
require_once dirname(__DIR__).'/runtime/commands.php';
require_once dirname(__DIR__, 2).'/runtime.php';

/**
 * Recreate the legacy BFQ/sysctl configuration shipped with PMSS.
 */
function pmssEnsureLegacySysctlBaseline(?callable $logger = null, ?string $targetOverride = null, bool $reload = true, ?string $modulesLoadOverride = null): void
{
    $log             = pmssSelectLogger($logger);
    $target          = $targetOverride ?? '/etc/sysctl.d/1-pmss-defaults.conf';
    $modulesLoadPath = $modulesLoadOverride ?? '/etc/modules-load.d/pmss-bbr.conf';
    // Persist TCP BBR module loading across reboots.
    $modulesContent  = "# PMSS: enable TCP BBR\n";
    $modulesContent .= "tcp_bbr\n";

    $lines = ['# Pulsed Media Config'];

    // /sys block tuning is handled by the boot-time tuning service; sysctl only covers /proc/sys.

    // Network and Security Hardening
    $lines[] = '';
    $lines[] = 'net.core.default_qdisc = fq';
    $lines[] = 'net.ipv4.tcp_congestion_control = bbr';
    $lines[] = 'net.ipv4.ip_forward = 1';
    $lines[] = 'fs.protected_regular = 2';
    $lines[] = 'fs.protected_fifos = 2';
    $lines[] = 'kernel.yama.ptrace_scope = 1';
    $lines[] = 'kernel.kptr_restrict = 1';

    $content = implode("\n", $lines);

    // Check if file needs updating
    $existing = @file_get_contents($target);
    $sysctlUpToDate = $existing !== false && trim($existing) === trim($content);
    if ($sysctlUpToDate) {
        $log('[SKIP] Legacy sysctl defaults already present and up to date');
    } else {
        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }
        @file_put_contents($target, $content.PHP_EOL);
    }

    $modulesExisting = @file_get_contents($modulesLoadPath);
    $modulesUpToDate = $modulesExisting !== false && trim($modulesExisting) === trim($modulesContent);
    if ($modulesUpToDate) {
        $log('[SKIP] TCP BBR modules-load configuration already present and up to date');
    } else {
        if (!is_dir(dirname($modulesLoadPath))) {
            @mkdir(dirname($modulesLoadPath), 0755, true);
        }
        if (@file_put_contents($modulesLoadPath, $modulesContent) === false) {
            $log('[WARN] Unable to write TCP BBR modules-load configuration at '.$modulesLoadPath);
        } else {
            $log('Refreshed TCP BBR modules-load configuration at '.$modulesLoadPath);
        }
    }

    if ($sysctlUpToDate) {
        return;
    }

    if ($reload) {
        runStep('Reloading sysctl configuration', 'sysctl --system');
    } else {
        $log('[SKIP] sysctl reload disabled');
    }
    $log('Refreshed legacy sysctl defaults at '.$target);
}
