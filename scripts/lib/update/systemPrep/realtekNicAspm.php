<?php
/** PMSS-managed PCIe ASPM pin for Realtek Ethernet controllers. */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/commands.php';

/** Install the udev rule that keeps ASPM off on Realtek Ethernet links and apply it to present devices. */
function pmssEnsureRealtekNicAspmOff(?callable $logger = null, ?string $target = null, ?callable $commandRunner = null, ?callable $commandPath = null): void
{
    $log = $logger ?: 'logMessage';
    $target = $target ?? '/etc/udev/rules.d/71-pmss-realtek-nic-aspm.rules';
    $commandRunner = $commandRunner ?: 'runStep';
    $commandPath = $commandPath ?: 'pmssCommandPath';
    // bind runs after the driver probe, so a driver that enables ASPM at probe is overridden.
    $match = 'ACTION=="add|bind|change", SUBSYSTEM=="pci", ATTR{vendor}=="0x10ec", ATTR{class}=="0x020000"';
    $content = "# PMSS-managed: keep PCIe ASPM off on Realtek Ethernet controllers (RTL8111/8168 transmit queue stalls).\n"
        .$match.', TEST=="link/l1_aspm", ATTR{link/l1_aspm}="0"'."\n"
        .$match.', TEST=="link/l0s_aspm", ATTR{link/l0s_aspm}="0"'."\n";
    $label = 'Realtek NIC ASPM rule';
    $options = pmssManagedPathInstallOptions($target, $label, ['mode' => 0644]);
    if (!pmssRefreshManagedPathFile($target, $content, $label, $log, $options)) {
        return;
    }

    $udevadm = $commandPath('udevadm');
    if ($udevadm === '') {
        $log('[WARN] udevadm unavailable; Realtek NIC ASPM rule will apply on the next device event');
        return;
    }
    if ($commandRunner('Reloading udev rules for Realtek NIC ASPM', escapeshellarg($udevadm).' control --reload') !== 0) {
        $log('[WARN] Unable to reload udev rules for Realtek NIC ASPM');
        return;
    }
    if ($commandRunner('Applying Realtek NIC ASPM rule', escapeshellarg($udevadm).' trigger --subsystem-match=pci --attr-match=vendor=0x10ec --attr-match=class=0x020000 --action=change') !== 0) {
        $log('[WARN] Unable to trigger Realtek Ethernet devices for the ASPM rule');
    }
}
