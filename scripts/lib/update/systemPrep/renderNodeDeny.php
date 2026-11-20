<?php
/** Per-account render-node deny (Intel i915/xe, AMD amdgpu): a late udev rule adds user:<name>:--- for gpuEnabled=false accounts (ADR 0096 amendments 2026-11-20). */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../dpkgSelections.php';
require_once dirname(__DIR__, 2).'/user/selection.php';
require_once dirname(__DIR__, 2).'/user/userConfigStore.php';

/** Kernel DRM drivers whose render nodes PMSS opens to accounts (ADR 0096): Intel i915/xe and AMD amdgpu (in-tree, no DKMS). */
function pmssRenderNodeDrivers(): array
{
    return ['i915', 'xe', 'amdgpu'];
}

/** Apply operator-owned per-account denies after uaccess, then verify the live ACLs. */
function pmssEnsureIntelRenderNodeDenies(
    ?callable $logger = null, ?string $target = null, ?callable $commandRunner = null,
    ?callable $commandPath = null, ?callable $intelProbe = null, ?callable $userLoader = null,
    ?callable $userLookup = null, ?callable $nodeLister = null
): void {
    $log = $logger ?: 'logMessage';
    $intelProbe = $intelProbe ?: 'pmssHostHasRenderGpuDisplayPciDevice';
    if (!$intelProbe()) return;
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        $log('[SKIP] Dry run: Intel render-node denies unchanged');
        return;
    }
    $target = $target ?? '/etc/udev/rules.d/74-pmss-render-node-deny.rules';
    $commandRunner = $commandRunner ?: 'runStep';
    $commandPath = $commandPath ?: 'pmssCommandPath';
    $userLoader = $userLoader ?: function (): array { return (new UserConfigStore())->loadAll(); };
    $userLookup = $userLookup ?: 'posix_getpwnam';
    $denied = [];
    $lines = [];
    foreach ($userLoader() as $name => $payload) {
        $name = (string) $name;
        // No home markers or caller-supplied names may enter root's udev RUN list.
        if (!pmssValidateUsername($name) || !is_array($payload)
            || pmssUserConfigNormaliseToggleValue($payload, 'gpuEnabled')) continue;
        $account = $userLookup($name);
        if (!is_array($account) || (int) ($account['uid'] ?? 0) <= 0) continue;
        $denied[$name] = (int) $account['uid'];
        $lines[] = 'SUBSYSTEM=="drm", KERNEL=="renderD*", DRIVERS=="'.implode('|', pmssRenderNodeDrivers()).'", RUN+="/usr/bin/setfacl -m u:'.$name.':--- $devnode"';
    }
    $removed = false;
    if ($lines !== []) {
        pmssInstallManagedPathFile($target, implode("\n", $lines)."\n", 'Intel render-node deny rule', $log, ['mode' => 0644], $installed);
        if (!$installed) return;
    } elseif (pmssPathExistsOrLink($target)) {
        $removed = pmssRemoveManagedPathFile($target, 'Intel render-node deny rule', $log);
        if (!$removed) return;
    }

    // Even unchanged rules must repair ACLs that a console seat change flushed.
    if ($lines !== [] || $removed) {
        $udevadm = $commandPath('udevadm');
        if ($udevadm === '') {
            $log('[WARN] udevadm unavailable; Intel render-node denies await the next device event');
        } elseif ($commandRunner('Reloading udev rules for Intel render denies', escapeshellarg($udevadm).' control --reload') !== 0) {
            $log('[WARN] Unable to reload udev rules for Intel render denies');
        } else {
            if ($commandRunner('Applying Intel render-node denies', escapeshellarg($udevadm).' trigger --subsystem-match=drm --action=change') !== 0) {
                $log('[WARN] Unable to trigger DRM devices for Intel render denies');
            }
            // trigger is asynchronous; read-back must follow completion of the RUN list.
            if ($commandRunner('Waiting for Intel render-node denies', escapeshellarg($udevadm).' settle') !== 0) {
                $log('[WARN] Unable to wait for Intel render-node denies');
            }
        }
    }
    $nodeLister = $nodeLister ?: function (): array {
        $nodes = [];
        foreach (glob('/sys/class/drm/renderD*') ?: [] as $device) {
            if (in_array(basename((string) realpath($device.'/device/driver')), pmssRenderNodeDrivers(), true)) {
                $nodes[] = '/dev/dri/'.basename($device);
            }
        }
        return $nodes;
    };
    $nodes = $denied === [] ? [] : $nodeLister();
    $getfacl = $denied === [] ? '' : $commandPath('getfacl');
    $missing = 0;
    foreach ($denied as $name => $uid) {
        $verified = $nodes !== [] && $getfacl !== '';
        foreach ($nodes as $node) {
            // The numeric qualifier avoids NSS display differences in getfacl output.
            $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = [];
            $rc = $getfacl === '' ? 1 : $commandRunner('Checking Intel render deny for '.pmssLogSafeLabel($name),
                escapeshellarg($getfacl).' --numeric --omit-header --absolute-names '.escapeshellarg($node));
            $output = (string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? '');
            if ($rc !== 0 || preg_match('/^user:'.$uid.':---[\t ]*$/m', $output) !== 1) $verified = false;
        }
        if (!$verified) {
            $missing++;
            $log('[WARN] Intel render deny missing for '.pmssLogSafeLabel($name));
        }
    }
    $log(sprintf('Intel render denies: %d accounts, %d verified, %d missing', count($denied), count($denied) - $missing, $missing));
}
