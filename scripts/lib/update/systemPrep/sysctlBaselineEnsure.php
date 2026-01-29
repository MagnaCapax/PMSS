<?php
/**
 * Legacy sysctl baseline renderer for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/logging.php';
require_once dirname(__DIR__).'/runtime/commands.php';
require_once dirname(__DIR__, 2).'/runtime.php';

if (!function_exists('pmssEnsureLegacySysctlBaseline')) {
    /**
     * Recreate the legacy BFQ/sysctl configuration shipped with PMSS.
     */
    function pmssEnsureLegacySysctlBaseline(?callable $logger = null, ?string $targetOverride = null, bool $reload = true): void
    {
        $log    = pmssSelectLogger($logger);
        $target = $targetOverride ?? '/etc/sysctl.d/1-pmss-defaults.conf';

        $lines = ['# Pulsed Media Config'];

        // Dynamically detect sd* devices for scheduling/readahead tuning
        $disks = glob('/sys/block/sd*');
        if ($disks) {
            foreach ($disks as $path) {
                $dev = basename($path);
                // Only tune if not a partition (glob matches block devices, so sda is fine, sda1 isn't in /sys/block)
                if (preg_match('/^sd[a-z]+$/', $dev)) {
                    $lines[] = "block/{$dev}/queue/scheduler = bfq";
                    $lines[] = "block/{$dev}/queue/read_ahead_kb = 1024";
                }
            }
        }

        // Network and Security Hardening
        $lines[] = '';
        $lines[] = 'net.ipv4.ip_forward = 1';
        $lines[] = 'fs.protected_regular = 2';
        $lines[] = 'fs.protected_fifos = 2';
        $lines[] = 'kernel.yama.ptrace_scope = 1';
        $lines[] = 'kernel.kptr_restrict = 1';

        $content = implode("\n", $lines);

        // Check if file needs updating
        $existing = @file_get_contents($target);
        if ($existing !== false && trim($existing) === trim($content)) {
            $log('[SKIP] Legacy sysctl defaults already present and up to date');
            return;
        }

        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }
        @file_put_contents($target, $content.PHP_EOL);
        if ($reload) {
            runStep('Reloading sysctl configuration', 'sysctl --system');
        } else {
            $log('[SKIP] sysctl reload disabled');
        }
        $log('Refreshed legacy sysctl defaults at '.$target);
    }
}

