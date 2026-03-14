<?php
/**
 * Base system preparation helpers executed during update-step2.
 *
 * This remains the historical include path for system preparation while also
 * carrying the small baseline helpers that are only consumed through this
 * surface.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/../runtime.php';

require_once __DIR__.'/systemPrep/cgroupsEnsureConfigured.php';

/**
 * Return total system memory in MiB (rounded).
 */
function pmssTotalMemMiB(): int
{
    if (is_string($override = getenv('PMSS_TOTAL_MEM_MIB')) && ctype_digit($override)) {
        return (int) $override;
    }

    return preg_match('/^MemTotal:\s+([0-9]+)/m', (string) @file_get_contents('/proc/meminfo'), $matches) === 1
        ? (int) round(((int) $matches[1]) / 1024)
        : 0;
}

/**
 * Return total logical CPU threads.
 */
function pmssTotalCpuThreads(): int
{
    if (is_string($override = getenv('PMSS_TOTAL_CPU_THREADS')) && ctype_digit($override)) {
        return (int) $override;
    }

    // Robust check using /proc/cpuinfo
    $count = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
    // Fallback to nproc if available
    return $count > 0 ? $count : max(0, (int) trim((string) @shell_exec('nproc')));
}

require_once __DIR__.'/systemPrep/systemdSlicesEnsure.php';
require_once __DIR__.'/systemPrep/localeBaselineEnsure.php';
require_once __DIR__.'/systemPrep/bootDefaultsEnsure.php';

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

    $reload ? runStep('Reloading sysctl configuration', 'sysctl --system') : $log('[SKIP] sysctl reload disabled');
    $log('Refreshed legacy sysctl defaults at '.$target);
}

/**
 * Install and enable the PMSS boot tuning script + systemd unit.
 */
function pmssEnsureBootTuning(?callable $logger = null, ?string $scriptTarget = null, ?string $serviceTarget = null): void
{
    $log = pmssSelectLogger($logger);
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $scriptTemplate = $cfgDir.'/template.pmss-boot-tuning.sh';
    $serviceTemplate = $cfgDir.'/template.systemd.pmss-boot-tuning.service';

    foreach ([$scriptTemplate => 'Boot tuning script template', $serviceTemplate => 'Boot tuning service template'] as $template => $label) {
        if (!is_file($template)) {
            $log('[SKIP] '.$label.' missing: '.$template);
            return;
        }
    }

    $scriptTarget = $scriptTarget ?? '/usr/local/sbin/pmss-boot-tuning.sh';
    $serviceTarget = $serviceTarget ?? '/etc/systemd/system/pmss-boot-tuning.service';

    $scriptRaw = @file_get_contents($scriptTemplate);
    if ($scriptRaw === false) {
        $log('[WARN] Unable to read boot tuning script template: '.$scriptTemplate);
        return;
    }

    $serviceRaw = @file_get_contents($serviceTemplate);
    if ($serviceRaw === false) {
        $log('[WARN] Unable to read boot tuning service template: '.$serviceTemplate);
        return;
    }
    $serviceRaw = str_replace('%%PMSS_BOOT_TUNING_SCRIPT%%', $scriptTarget, $serviceRaw);

    // Write files only when content changes to keep the run idempotent.
    $writeTarget = static function (string $path, string $content, int $mode, string $label) use ($log): bool {
        $existing = @file_get_contents($path);
        if ($existing !== false && $existing === $content) {
            $log('[SKIP] '.$label.' already present and up to date');
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $log('[WARN] Unable to create '.$label.' directory: '.$dir);
            return false;
        }
        $tmp = $path.'.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            $log('[WARN] Unable to write '.$label.' at '.$tmp);
            return false;
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            $log('[WARN] Unable to install '.$label.' at '.$path);
            @unlink($tmp);
            return false;
        }
        $log('Installed '.$label.' at '.$path);
        return true;
    };

    $writeTarget($scriptTarget, $scriptRaw, 0755, 'Boot tuning script');
    $writeTarget($serviceTarget, $serviceRaw, 0644, 'Boot tuning service');

    if (getenv('PMSS_DRY_RUN') === '1' || (defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true)) {
        pmssLogStatus('SKIP', 'Enabling PMSS boot tuning service (test/dry-run)');
        return;
    }
    if (!is_dir('/run/systemd/system')) {
        pmssLogStatus('SKIP', 'Enabling PMSS boot tuning service (systemd unavailable)');
        return;
    }

    runStep('Reloading systemd unit files (PMSS boot tuning)', 'systemctl daemon-reload || true');
    runStep('Enabling PMSS boot tuning service', 'systemctl enable pmss-boot-tuning.service || true');
    runStep('Starting PMSS boot tuning service', 'systemctl start pmss-boot-tuning.service || true');
}

/**
 * Ensure root shell defaults mirror the historical installer behaviour.
 */
function pmssConfigureRootShellDefaults(?callable $logger = null): void
{
    $log    = pmssSelectLogger($logger);
    $bashrc = '/root/.bashrc';
    $lines = file_exists($bashrc) ? (file($bashrc, FILE_IGNORE_NEW_LINES) ?: []) : [];

    $defaults = [
        "alias ls='ls --color=auto'",
        'PATH=$PATH:/scripts',
    ];
    if (($missing = array_diff($defaults, $lines)) === []) {
        $log('[SKIP] Root shell defaults already configured');
        return;
    }

    @file_put_contents($bashrc, implode(PHP_EOL, array_merge($lines, $missing)).PHP_EOL);
    $log('Appended root shell defaults: '.implode(', ', $missing));
}
