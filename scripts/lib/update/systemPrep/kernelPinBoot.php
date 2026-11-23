<?php
/**
 * Pinned-image boot preparation using the existing boot-default publication gate.
 *
 * @license GPL-3.0-only
 */

/** Report operator activation pins without interpreting or rewriting shell values. */
function pmssPinnedKernelGrubDefaultLog(string $grubPath, callable $log): void
{
    foreach (@file($grubPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*GRUB_DEFAULT\s*=\s*(.*)$/', $line, $match) !== 1) continue;
        $raw = trim($match[1]);
        if ($raw !== '0' && $raw !== '"0"' && $raw !== "'0'") {
            $log('[WARN] Pinned kernel activation: GRUB_DEFAULT='.$raw.'; host may not boot the new kernel automatically');
        }
    }
}

/** Read the installed config, persist managed defaults, then regenerate the boot menu. */
function pmssPinnedKernelBootPrepare(string $bootPath, string $grubPath, callable $log): void
{
    $config = $bootPath.'/config-'.PMSS_KERNEL_PIN_RELEASE;
    if (!is_readable($config)) {
        $log('[WARN] Pinned kernel config not readable: '.$config.'; first-boot BPF LSM not prepared');
        return;
    }
    $lines = @file($grubPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $log('[WARN] '.$grubPath.' not readable; first-boot BPF LSM not prepared');
        return;
    }
    // Target mode ignores the old kernel's active LSM list, preserving selection semantics.
    if (!pmssBootDefaultsEnsureBpfLsm($lines, $grubPath, $log, $config, null, true)) {
        // No change is right only when an lsm= list already carries bpf; otherwise the planner declined (shell
        // expression, malformed line, no BPF LSM in the build) and the first boot would leave socket privacy off.
        if (preg_match('/^GRUB_CMDLINE_LINUX(?:_DEFAULT)?=.*[\s"\']lsm=(?:[a-z0-9_]+,)*bpf(?:,|[\s"\'])/m', implode("\n", $lines)) !== 1) {
            $log('[WARN] Pinned kernel: first-boot BPF LSM not prepared in '.$grubPath.'; socket-table privacy stage 2 stays unloaded on that boot');
        }
        return;
    }
    $written = pmssWriteManagedPathFileWithBackup($grubPath, $lines, 'grub', $log);
    pmssBootDefaultsApplyGrubChanges($written, $grubPath, '', $log);
}
