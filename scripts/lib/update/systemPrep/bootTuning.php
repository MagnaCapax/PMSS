<?php
/** Ordered boot-tuning artifact installation; loaded by systemPrep.php. @license GPL-3.0-only */

/**
 * Install and enable the PMSS boot tuning script + systemd unit.
 */
function pmssEnsureBootTuning(?callable $logger = null, ?string $scriptTarget = null, ?string $serviceTarget = null): void
{
    $log = $logger ?: 'logMessage';
    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $scriptTarget = $scriptTarget ?? '/usr/local/sbin/pmss-boot-tuning.sh';
    $files = [
        [$cfgDir.'/template.pmss-boot-tuning.sh', $scriptTarget, 0755, 'Boot tuning script'],
        [$cfgDir.'/template.systemd.pmss-boot-tuning.service', $serviceTarget ?? '/etc/systemd/system/pmss-boot-tuning.service', 0644, 'Boot tuning service'],
    ];

    // Preflight both templates before reading either, and read both before writing.
    foreach ($files as [$template, $target, $mode, $label]) {
        if (!is_file($template)) {
            $log('[SKIP] '.$label.' template missing: '.$template);
            return;
        }
    }
    foreach ($files as $index => [$template, $target, $mode, $label]) {
        $raw = @file_get_contents($template);
        if ($raw === false) {
            $log('[WARN] Unable to read '.strtolower($label).' template: '.$template);
            return;
        }
        // The script is verbatim; only the service contains a managed target token.
        $files[$index][] = $index === 1 ? str_replace('%%PMSS_BOOT_TUNING_SCRIPT%%', $scriptTarget, $raw) : $raw;
    }

    // Write files only when content changes to keep the run idempotent.
    foreach ($files as [$template, $target, $mode, $label, $content]) {
        pmssInstallManagedPathFile($target, $content, $label, $log, ['mode' => $mode]);
    }

    if (pmssSystemdActionSkip(pmssSystemdActionSkipReason(null, true, true), 'Enabling PMSS boot tuning service')) return;
    runStep('Reloading systemd unit files (PMSS boot tuning)', 'systemctl daemon-reload || true');
    runStep('Enabling PMSS boot tuning service', 'systemctl enable pmss-boot-tuning.service || true');
    runStep('Starting PMSS boot tuning service', 'systemctl start pmss-boot-tuning.service || true');
}
