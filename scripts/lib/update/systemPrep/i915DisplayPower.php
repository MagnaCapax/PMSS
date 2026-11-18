<?php
/** PMSS-managed i915 display power-state pin for Intel iGPU hosts (ADR 0096). */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../packageState.php';

/**
 * Keep i915 display power states (DC5/DC6) off on Intel display hosts, and make sure every
 * initrd carries the option: i915 loads from the initrd, before the root filesystem is mounted.
 */
function pmssEnsureI915DisplayPowerStatesOff(?callable $logger = null, ?string $target = null, ?callable $intelProbe = null, ?callable $commandRunner = null, ?callable $commandPath = null, ?string $bootDir = null): void
{
    $log = $logger ?: 'logMessage';
    $target = $target ?? '/etc/modprobe.d/pmss-i915-dc.conf';
    $intelProbe = $intelProbe ?: 'pmssHostHasIntelDisplayPciDevice';
    $commandRunner = $commandRunner ?: 'runStep';
    $commandPath = $commandPath ?: 'pmssCommandPath';
    if (!$intelProbe()) return;
    $content = "# PMSS-managed: keep i915 display power states (DC5/DC6) off. With the DMC firmware loaded,\n"
        ."# some headless Intel minis hang about 20 s after every boot (MCE broadcast timeout). ADR 0096.\n"
        ."options i915 enable_dc=0\n";
    $installed = false;
    pmssInstallManagedPathFile($target, $content, 'i915 display power-state option', $log, ['mode' => 0644], $installed);
    if (!$installed) return;

    $lsinitramfs = $commandPath('lsinitramfs');
    $updateInitramfs = $commandPath('update-initramfs');
    if ($lsinitramfs === '' || $updateInitramfs === '') {
        $log('[WARN] initramfs-tools unavailable; the i915 power-state option is not confirmed in the initrd');
        return;
    }
    $bootDir = $bootDir ?? '/boot';
    if (pmssI915InitrdsMissingOption($bootDir, $target, $lsinitramfs, $commandRunner) === []) return;
    if ($commandRunner('Rebuilding initramfs for the i915 power-state option', escapeshellarg($updateInitramfs).' -u -k all') !== 0) {
        $log('[WARN] update-initramfs failed; the i915 power-state option is not in every initrd yet');
        return;
    }
    $missing = pmssI915InitrdsMissingOption($bootDir, $target, $lsinitramfs, $commandRunner);
    if ($missing !== []) $log('[WARN] i915 power-state option still missing from '.implode(', ', $missing));
}

/** Names of the initrds under $bootDir that do not contain $target. */
function pmssI915InitrdsMissingOption(string $bootDir, string $target, string $lsinitramfs, callable $commandRunner): array
{
    $missing = [];
    foreach (@glob(rtrim($bootDir, '/').'/initrd.img-*') ?: [] as $initrd) {
        $command = 'sh -c '.escapeshellarg(escapeshellarg($lsinitramfs).' '.escapeshellarg($initrd).' | grep -qxF '.escapeshellarg(ltrim($target, '/')));
        // rc 1 means "not in this initrd": a finding, not a failed step.
        if ($commandRunner('Checking '.basename($initrd).' for the i915 power-state option', $command, [1]) !== 0) $missing[] = basename($initrd);
    }
    return $missing;
}
