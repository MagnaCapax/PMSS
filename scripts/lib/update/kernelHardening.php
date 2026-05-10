<?php
/**
 * Kernel module hardening helpers for update-step2.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/managedPath.php';

/** Render the Dirty Frag mitigation blacklist body. */
function pmssDirtyFragBlacklistBody(): string
{
    // Vendor-grade module set: V4bel writeup names esp4/esp6/rxrpc; AWS bulletin
    // 2026-027 + Sysdig analysis identify ipcomp/ipcomp6 (additional xfrm code
    // paths reaching the same skb_input vulnerable handler) and xfrm_user (the
    // netlink interface that AUTOLOADS esp4/ipcomp via MODULE_ALIAS_XFRM_TYPE).
    //
    // POLICY: xfrm_user blacklist blocks future autoload. PMSS does NOT use
    // IPsec / strongswan / legacy xfrm consumers today (verified empty
    // `ip xfrm policy` fleet-wide). If a future PMSS feature requires xfrm_user,
    // lift the xfrm_user line from this blacklist BEFORE adding that feature.
    // WireGuard, Docker rootless, ixgbe, customer workloads are unaffected.
    return "# PMSS: block autoload of Dirty Frag attack-surface modules\n"
        ."# Module set: V4bel PoC + AWS bulletin 2026-027 + Sysdig analysis\n"
        ."install esp4 /bin/false\n"
        ."install esp6 /bin/false\n"
        ."install rxrpc /bin/false\n"
        ."install ipcomp /bin/false\n"
        ."install ipcomp6 /bin/false\n"
        ."install xfrm_user /bin/false\n";
}

/**
 * Parse a lsmod-style snapshot for the Dirty Frag module set.
 *
 * @return array<string, int>|null
 */
function pmssDirtyFragModulesLoaded(): ?array
{
    $snapshotPath = getenv('PMSS_LSMOD_OUTPUT_PATH');
    if (is_string($snapshotPath) && $snapshotPath !== '') {
        $snapshot = @file_get_contents($snapshotPath);
    } else {
        $snapshot = @shell_exec('lsmod 2>/dev/null');
    }
    if (!is_string($snapshot)) {
        return null;
    }

    $loaded = [];
    foreach (preg_split('/\r?\n/', trim($snapshot)) ?: [] as $line) {
        $columns = preg_split('/\s+/', trim($line));
        if (!is_array($columns) || count($columns) < 3 || $columns[0] === 'Module') {
            continue;
        }
        if (!in_array($columns[0], ['esp4', 'esp6', 'rxrpc', 'ipcomp', 'ipcomp6', 'xfrm_user'], true)) {
            continue;
        }
        $loaded[$columns[0]] = ctype_digit($columns[2]) ? (int) $columns[2] : 0;
    }

    return $loaded;
}

/** Apply the Dirty Frag blacklist and attempt runtime eviction of loaded modules. */
function pmssEnsureDirtyFragBlacklist(callable $log, ?callable $runner = null): void
{
    $runner = $runner ?: 'runStep';
    $blacklistPath = pmssResolvePathFromEnv('PMSS_DIRTYFRAG_MODPROBE_PATH', '/etc/modprobe.d/dirtyfrag.conf');
    $flagPath = pmssResolvePathFromEnv('PMSS_DIRTYFRAG_FLAG_PATH', pmssRuntimeDir().'/dirtyfrag-modules-loaded');
    $blacklistBody = pmssDirtyFragBlacklistBody();
    $existingBody = is_file($blacklistPath) ? (string) @file_get_contents($blacklistPath) : '';

    if ($existingBody === $blacklistBody) {
        $log('[SKIP] Dirty Frag blacklist already up to date at '.$blacklistPath);
    } elseif (!pmssDirEnsureExists(dirname($blacklistPath), 0755)) {
        $log('[WARN] Failed to create Dirty Frag modprobe directory '.dirname($blacklistPath));
        return;
    } elseif (pmssWriteManagedPathFile($blacklistPath, $blacklistBody, 'Dirty Frag blacklist', $log)) {
        $log('Updated '.$blacklistPath);
    } else {
        return;
    }

    // xfrm_user is intentionally NOT in the rmmod set: it has use count > 0 on
    // most live hosts (in-use by kernel network stack — netns refs, iproute2,
    // etc.) and rmmod would fail. The blacklist alone protects post-reboot
    // autoload, which is the relevant defensive surface for xfrm_user.
    $runner(
        'Unloading Dirty Frag modules (esp4 esp6 rxrpc ipcomp ipcomp6)',
        'bash -lc '.escapeshellarg('modprobe -r esp4 esp6 rxrpc ipcomp ipcomp6 >/dev/null 2>&1 || true')
    );

    $loaded = pmssDirtyFragModulesLoaded();
    if ($loaded === null) {
        $log('[WARN] Unable to inspect Dirty Frag modules after unload attempt');
        return;
    }
    if ($loaded === []) {
        is_file($flagPath) && !pmssRemoveManagedPathFile($flagPath, 'Dirty Frag runtime flag', $log);
        return;
    }

    $parts = [];
    $pinned = [];
    foreach ($loaded as $module => $useCount) {
        $parts[] = $module.'(use='.$useCount.')';
        if ($useCount > 0) {
            $pinned[] = $module."\t".$useCount;
        }
    }
    $log('[WARN] Dirty Frag modules still loaded after unload attempt: '.implode(', ', $parts));

    if ($pinned === []) {
        is_file($flagPath) && !pmssRemoveManagedPathFile($flagPath, 'Dirty Frag runtime flag', $log);
        return;
    }
    if (!pmssDirEnsureExists(dirname($flagPath), 0755)) {
        $log('[WARN] Failed to create Dirty Frag runtime directory '.dirname($flagPath));
        return;
    }
    if (pmssWriteManagedPathFile($flagPath, implode(PHP_EOL, $pinned).PHP_EOL, 'Dirty Frag runtime flag', $log)) {
        $log('[WARN] Dirty Frag runtime flag written to '.$flagPath);
    }
}

/** Render the Copy Fail mitigation blacklist body. */
function pmssAlgifAeadBlacklistBody(): string
{
    return "# PMSS: block Copy Fail attack-surface module\n"
        ."install algif_aead /bin/false\n";
}

/** Return the warning text used when algif_aead is built into the kernel. */
function pmssAlgifAeadBuiltInKernelWarning(): string
{
    return 'algif_aead is built into this kernel; the PMSS modprobe blacklist is ineffective. '
        .'Install a patched kernel and reboot, or add initcall_blacklist=algif_aead_init until the patch lands.';
}

/** Detect whether the running kernel builds algif_aead into the image. */
function pmssAlgifAeadKernelBuiltIn(): bool
{
    $kernelConfigPath = pmssResolvePathFromEnv('PMSS_ALGIF_KERNEL_CONFIG_PATH', '');
    if ($kernelConfigPath === '') {
        $release = trim((string) php_uname('r'));
        if ($release === '') {
            return false;
        }
        $kernelConfigPath = '/boot/config-'.$release;
    }

    return is_string($kernelConfig = @file_get_contents($kernelConfigPath))
        && preg_match('/^CONFIG_CRYPTO_USER_API_AEAD=y$/m', $kernelConfig) === 1;
}

/** Apply the Copy Fail mitigation blacklist and attempt runtime eviction. */
function pmssEnsureAlgifAeadBlacklist(callable $log, ?callable $runner = null): void
{
    $runner = $runner ?: 'runStep';
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        $log('[DRY-RUN] Copy Fail algif_aead blacklist: skipping all mutations');
        return;
    }

    $blacklistPath = pmssResolvePathFromEnv('PMSS_ALGIF_MODPROBE_PATH', '/etc/modprobe.d/pmss-algif-blacklist.conf');
    $blacklistBody = pmssAlgifAeadBlacklistBody();
    $existingBody = is_file($blacklistPath) ? (string) @file_get_contents($blacklistPath) : '';

    if ($existingBody === $blacklistBody) {
        $log('[SKIP] Copy Fail algif_aead blacklist already up to date at '.$blacklistPath);
    } elseif (!pmssDirEnsureExists(dirname($blacklistPath), 0755)) {
        $log('[WARN] Failed to create Copy Fail modprobe directory '.dirname($blacklistPath));
        return;
    } elseif (pmssWriteManagedPathFile($blacklistPath, $blacklistBody, 'Copy Fail algif_aead blacklist', $log)) {
        @chmod($blacklistPath, 0644);
        $log('Updated '.$blacklistPath);
    } else {
        return;
    }

    $runner(
        'Unloading Copy Fail algif_aead kernel module',
        'bash -lc '.escapeshellarg('modprobe -r algif_aead >/dev/null 2>&1 || true')
    );

    if (!pmssAlgifAeadKernelBuiltIn()) {
        return;
    }

    $warning = pmssAlgifAeadBuiltInKernelWarning();
    if (!is_string($log) || !in_array($log, ['logMessage', 'logmsg'], true)) {
        $log('[WARN] '.$warning);
    }
    pmssLogStatus('WARN', $warning, 0);
}
