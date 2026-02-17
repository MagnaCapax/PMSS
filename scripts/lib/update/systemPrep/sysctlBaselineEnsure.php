<?php
/**
 * Legacy sysctl baseline renderer for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/runtime/commands.php';

/**
 * Recreate the legacy BFQ/sysctl configuration shipped with PMSS.
 */
function pmssEnsureLegacySysctlBaseline(?callable $logger = null, ?string $targetOverride = null, bool $reload = true, ?string $modulesLoadOverride = null): void
{
    $log             = pmssSelectLogger($logger);
    $target          = $targetOverride ?? '/etc/sysctl.d/1-pmss-defaults.conf';
    $modulesLoadPath = $modulesLoadOverride ?? '/etc/modules-load.d/pmss-bbr.conf';
    // Persist TCP BBR module loading across reboots.
    $modulesContent = "# PMSS: enable TCP BBR\ntcp_bbr\n";

    // /sys block tuning is handled by the boot-time tuning service; sysctl only covers /proc/sys.
    // Network and Security Hardening
    $content = <<<'SYSCTL'
# Pulsed Media Config

net.core.default_qdisc = fq
net.ipv4.tcp_congestion_control = bbr
net.ipv4.ip_forward = 1
fs.protected_regular = 2
fs.protected_fifos = 2
kernel.yama.ptrace_scope = 1
kernel.kptr_restrict = 1
SYSCTL;

    // Check if file needs updating
    $existing = @file_get_contents($target);
    $sysctlUpToDate = $existing !== false && trim($existing) === trim($content);
    if ($sysctlUpToDate) {
        $log('[SKIP] Legacy sysctl defaults already present and up to date');
    } else {
        @mkdir(dirname($target), 0755, true);
        @file_put_contents($target, $content.PHP_EOL);
    }

    $modulesExisting = @file_get_contents($modulesLoadPath);
    $modulesUpToDate = $modulesExisting !== false && trim($modulesExisting) === trim($modulesContent);
    if ($modulesUpToDate) {
        $log('[SKIP] TCP BBR modules-load configuration already present and up to date');
    } else {
        @mkdir(dirname($modulesLoadPath), 0755, true);
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
