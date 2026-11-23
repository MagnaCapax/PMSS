<?php
/**
 * Debian 12 pinned kernel delivery; installation never activates or removes an image.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/../distro.php';
require_once __DIR__.'/../packageState.php';
require_once __DIR__.'/../apps/remoteBinary.php';
require_once __DIR__.'/kernelPinBoot.php';

const PMSS_KERNEL_PIN_PACKAGE = 'linux-image-7.3.4-zabbly+';
const PMSS_KERNEL_PIN_VERSION = '7.3.4-amd64-202611110309-debian12';
const PMSS_KERNEL_PIN_RELEASE = '7.3.4-zabbly+';
const PMSS_KERNEL_PIN_URL = 'https://pulsedmedia.com/remote/pkg/linux-image-7.3.4-zabbly+_7.3.4-amd64-202611110309-debian12_amd64.deb';
const PMSS_KERNEL_PIN_SHA256 = '2716133e4ad7af4245a41245bd2957526b21a3bcc1c37cb365e2ec0c9311c963';

/** Decide from observations only; one skip reason keeps fleet logs actionable. */
function pmssPinnedKernelPlan(array $state): array
{
    $reason = '';
    if ($state['distro']['name'] !== 'debian' || $state['distro']['version'] !== 12) {
        $reason = 'requires Debian 12';
    } elseif (preg_match('/^(install|hold|deinstall|purge) ok installed$/D', $state['status']) === 1
        && $state['version'] === PMSS_KERNEL_PIN_VERSION) {
        $reason = PMSS_KERNEL_PIN_PACKAGE.' already installed at '.PMSS_KERNEL_PIN_VERSION;
    } elseif ($state['hold'] !== null) {
        $reason = 'kernel-pin-hold: '.($state['hold'] !== '' ? $state['hold'] : 'safety hold (no readable reason)');
    } elseif ($state['dkms'] === null) {
        $reason = 'cannot inspect DKMS registrations';
    } elseif (($modules = array_values(array_diff($state['dkms'], ['wireguard']))) !== []) {
        $reason = 'DKMS module registered: '.implode(', ', $modules);
    } else {
        // Conservative free-space budget includes modules, the download, and generated initrd.
        foreach (['bootFree' => ['/boot', 250], 'rootFree' => ['/', 1300]] as $key => [$path, $required]) {
            $free = $state[$key];
            if ($free === false || $free < $required * 1048576) {
                $reason = $path.' free space '.($free === false ? 'unknown' : sprintf('%.0f bytes (%.1f MiB)', $free, $free / 1048576))
                    .'; required '.$required.' MiB';
                break;
            }
        }
    }
    if ($reason === '' && !$state['bpfPlanner']) {
        $reason = 'refusal: #1303 BPF LSM planner pmssBootDefaultsEnsureBpfLsm is absent';
    }
    return ['action' => $reason === '' ? 'install' : 'skip', 'reason' => $reason];
}

/** Observe injectable host paths without changing package or boot state. */
function pmssPinnedKernelState(): array
{
    $boot = pmssResolvePathFromEnv('PMSS_KERNEL_PIN_BOOT_PATH', '/boot');
    // The resolver trims trailing slashes; restore the literal filesystem root.
    $root = pmssResolvePathFromEnv('PMSS_KERNEL_PIN_ROOT_PATH', '/') ?: '/';
    $hold = pmssResolvePathFromEnv('PMSS_KERNEL_PIN_HOLD_PATH', '/etc/seedbox/config/kernel-pin-hold');
    $dkms = pmssResolvePathFromEnv('PMSS_KERNEL_PIN_DKMS_PATH', '/var/lib/dkms');
    $reason = null;
    if (pmssPathExistsOrLink($hold)) {
        $text = pmssReadRegularFileContents($hold) ?? '';
        $reason = trim((string) preg_replace('/[\x00-\x1f\x7f]/', ' ', explode("\n", $text, 2)[0]));
    }
    // DKMS also stores bookkeeping files here; only module directories register modules.
    $entries = is_dir($dkms) ? pmssDirectoryEntriesRead($dkms) : (pmssPathExistsOrLink($dkms) ? false : []);
    $modules = $entries === false ? null : [];
    foreach ($entries ?: [] as $entry) {
        if (is_dir($dkms.'/'.$entry)) $modules[] = $entry;
    }
    return [
        'distro' => pmssDetectDistro(), 'status' => pmssPackageStatus(PMSS_KERNEL_PIN_PACKAGE),
        'version' => pmssPackageVersion(PMSS_KERNEL_PIN_PACKAGE), 'hold' => $reason, 'dkms' => $modules,
        'bootFree' => @disk_free_space($boot), 'rootFree' => @disk_free_space($root),
        'bpfPlanner' => function_exists('pmssBootDefaultsEnsureBpfLsm'),
    ];
}

/** Apply one verified package pin; failures leave the remainder of the update running. */
function pmssEnsurePinnedKernel(callable $log): void
{
    $plan = pmssPinnedKernelPlan(pmssPinnedKernelState());
    if ($plan['action'] === 'skip') {
        $log('[SKIP] Pinned kernel: '.$plan['reason']);
        return;
    }
    $boot = pmssResolvePathFromEnv('PMSS_KERNEL_PIN_BOOT_PATH', '/boot');
    $grub = pmssResolvePathFromEnv('PMSS_KERNEL_PIN_GRUB_PATH', '/etc/default/grub');
    pmssPinnedKernelGrubDefaultLog($grub, $log);
    if (!pmssInstallPinnedRemoteDebPackage('kernel '.PMSS_KERNEL_PIN_RELEASE, PMSS_KERNEL_PIN_URL, PMSS_KERNEL_PIN_SHA256)) {
        $log('[WARN] Pinned kernel install failed; continuing update without activation');
        return;
    }
    // A dry run has no installed artifacts and must never publish boot defaults.
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) return;
    pmssPinnedKernelBootPrepare($boot, $grub, $log);
    foreach (['vmlinuz-', 'initrd.img-'] as $prefix) {
        if (!is_file($boot.'/'.$prefix.PMSS_KERNEL_PIN_RELEASE)) {
            $log('[WARN] Pinned kernel missing '.$boot.'/'.$prefix.PMSS_KERNEL_PIN_RELEASE);
        }
    }
    $menu = (string) @file_get_contents($boot.'/grub/grub.cfg');
    if (strpos($menu, PMSS_KERNEL_PIN_RELEASE) === false) {
        $log('[WARN] Pinned kernel '.PMSS_KERNEL_PIN_RELEASE.' absent from '.$boot.'/grub/grub.cfg');
    }
}
