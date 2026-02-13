<?php
/**
 * Boot-time system tuning service installation for update-step2.
 *
 * Ensures /sys tuning is applied at boot via a dedicated oneshot unit.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/logging.php';
require_once dirname(__DIR__).'/runtime/commands.php';
require_once dirname(__DIR__, 2).'/runtime.php';

/**
 * Install and enable the PMSS boot tuning script + systemd unit.
 */
function pmssEnsureBootTuning(?callable $logger = null, ?string $scriptTarget = null, ?string $serviceTarget = null): void
{
    $log = pmssSelectLogger($logger);
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $scriptTemplate = $cfgDir.'/template.pmss-boot-tuning.sh';
    $serviceTemplate = $cfgDir.'/template.systemd.pmss-boot-tuning.service';

    if (!is_file($scriptTemplate)) {
        $log('[SKIP] Boot tuning script template missing: '.$scriptTemplate);
        return;
    }
    if (!is_file($serviceTemplate)) {
        $log('[SKIP] Boot tuning service template missing: '.$serviceTemplate);
        return;
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

    $dryRun = getenv('PMSS_DRY_RUN') === '1';
    $testMode = defined('PMSS_TEST_MODE') && PMSS_TEST_MODE === true;
    if ($dryRun || $testMode) {
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
