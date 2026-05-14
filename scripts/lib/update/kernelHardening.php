<?php
/** Registry-driven kernel module hardening helpers for update-step2. */

require_once __DIR__.'/runtime/commands.php';
require_once __DIR__.'/managedPath.php';

const PMSS_KERNEL_HARDENING = [
    'copyfail' => [
        'path' => ['PMSS_ALGIF_MODPROBE_PATH', '/etc/modprobe.d/pmss-algif-blacklist.conf'], 'rationale' => "# PMSS: block Copy Fail attack-surface module\n",
        'modules' => ['algif_aead'], 'evict' => ['algif_aead'], 'label' => 'Copy Fail algif_aead blacklist', 'short' => 'Copy Fail', 'unload' => 'Unloading Copy Fail algif_aead kernel module',
        'builtin_kconfig' => 'CONFIG_CRYPTO_USER_API_AEAD', 'builtin_config_env' => 'PMSS_ALGIF_KERNEL_CONFIG_PATH',
        'builtin_warning' => 'algif_aead is built into this kernel; the PMSS modprobe blacklist is ineffective. '.'Install a patched kernel and reboot, or add initcall_blacklist=algif_aead_init until the patch lands.',
    ],
    'dirtyfrag' => [
        'path' => ['PMSS_DIRTYFRAG_MODPROBE_PATH', '/etc/modprobe.d/dirtyfrag.conf'], 'rationale' => "# PMSS: block autoload of Dirty Frag attack-surface modules\n"."# Module set: V4bel PoC + AWS bulletin 2026-027 + Sysdig analysis\n"."# espintcp added 2026-05-13 (Fragnesia / copyfail 3.0, Sam James / v12-security PoC)\n",
        'modules' => ['esp4', 'esp6', 'rxrpc', 'ipcomp', 'ipcomp6', 'xfrm_user', 'espintcp'],
        'evict' => ['esp4', 'esp6', 'rxrpc', 'ipcomp', 'ipcomp6', 'espintcp'], // xfrm_user is blacklisted but pinned on live hosts.
        'label' => 'Dirty Frag blacklist', 'short' => 'Dirty Frag', 'unload' => 'Unloading Dirty Frag modules (esp4 esp6 rxrpc ipcomp ipcomp6 espintcp)',
        'runtime_flag' => ['PMSS_DIRTYFRAG_FLAG_PATH', 'dirtyfrag-modules-loaded'],
    ],
];

function pmssModprobeBlacklistBody(array $spec): string
{
    $body = (string) $spec['rationale'];
    foreach ($spec['modules'] as $module) {
        $body .= 'install '.$module." /bin/false\n";
    }
    return $body;
}

function pmssDirtyFragBlacklistBody(): string { return pmssModprobeBlacklistBody(PMSS_KERNEL_HARDENING['dirtyfrag']); }
function pmssAlgifAeadBlacklistBody(): string { return pmssModprobeBlacklistBody(PMSS_KERNEL_HARDENING['copyfail']); }
function pmssAlgifAeadBuiltInKernelWarning(): string { return (string) PMSS_KERNEL_HARDENING['copyfail']['builtin_warning']; }

function pmssApplyKernelHardening(callable $log, ?callable $runner = null): void
{
    foreach (PMSS_KERNEL_HARDENING as $spec) {
        pmssApplyModprobeBlacklist($spec, $log, $runner);
    }
}

function pmssApplyModprobeBlacklist(array $spec, callable $log, ?callable $runner = null): void
{
    $runner = $runner ?: 'runStep';
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        $log('[DRY-RUN] '.$spec['label'].': skipping all mutations');
        return;
    }

    $blacklistPath = pmssResolvePathFromEnv($spec['path'][0], $spec['path'][1]);
    $blacklistBody = pmssModprobeBlacklistBody($spec);
    $existingBody = is_file($blacklistPath) ? (string) @file_get_contents($blacklistPath) : '';
    if ($existingBody === $blacklistBody) $log('[SKIP] '.$spec['label'].' already up to date at '.$blacklistPath);
    elseif (!pmssDirEnsureExists(dirname($blacklistPath), 0755)) { $log('[WARN] Failed to create '.$spec['short'].' modprobe directory '.dirname($blacklistPath)); return; }
    elseif (pmssWriteManagedPathFile($blacklistPath, $blacklistBody, $spec['label'], $log)) $log('Updated '.$blacklistPath);
    else return;

    $runner($spec['unload'], 'bash -lc '.escapeshellarg('modprobe -r '.implode(' ', $spec['evict']).' >/dev/null 2>&1 || true'));
    if (isset($spec['runtime_flag'])) {
        $flagPath = pmssResolvePathFromEnv($spec['runtime_flag'][0], pmssRuntimeDir().'/'.$spec['runtime_flag'][1]);
        $loaded = pmssKernelHardeningLoadedModules($spec['modules']);
        if ($loaded === null) { $log('[WARN] Unable to inspect '.$spec['short'].' modules after unload attempt'); return; }
        if ($loaded === []) {
            is_file($flagPath) && !pmssRemoveManagedPathFile($flagPath, $spec['short'].' runtime flag', $log);
            return;
        }
        $parts = [];
        $pinned = [];
        foreach ($loaded as $module => $useCount) { $parts[] = $module.'(use='.$useCount.')'; if ($useCount > 0) $pinned[] = $module."\t".$useCount; }
        $log('[WARN] '.$spec['short'].' modules still loaded after unload attempt: '.implode(', ', $parts));
        if ($pinned === []) {
            is_file($flagPath) && !pmssRemoveManagedPathFile($flagPath, $spec['short'].' runtime flag', $log);
            return;
        }
        if (!pmssDirEnsureExists(dirname($flagPath), 0755)) { $log('[WARN] Failed to create '.$spec['short'].' runtime directory '.dirname($flagPath)); return; }
        if (pmssWriteManagedPathFile($flagPath, implode(PHP_EOL, $pinned).PHP_EOL, $spec['short'].' runtime flag', $log)) {
            $log('[WARN] '.$spec['short'].' runtime flag written to '.$flagPath);
        }
    }
    if (isset($spec['builtin_kconfig']) && pmssKernelHardeningBuiltIn($spec)) {
        $warning = (string) $spec['builtin_warning'];
        if (!is_string($log) || !in_array($log, ['logMessage', 'logmsg'], true)) $log('[WARN] '.$warning);
        pmssLogStatus('WARN', $warning, 0);
    }
}

function pmssKernelHardeningBuiltIn(array $spec): bool
{
    if (!isset($spec['builtin_kconfig'])) return false;
    $kernelConfigPath = pmssResolvePathFromEnv((string) $spec['builtin_config_env'], '');
    if ($kernelConfigPath === '') {
        $release = trim((string) php_uname('r'));
        if ($release === '') return false;
        $kernelConfigPath = '/boot/config-'.$release;
    }
    return is_string($kernelConfig = @file_get_contents($kernelConfigPath))
        && preg_match('/^'.preg_quote((string) $spec['builtin_kconfig'], '/').'=y$/m', $kernelConfig) === 1;
}

function pmssKernelHardeningLoadedModules(array $modules): ?array
{
    $snapshotPath = getenv('PMSS_LSMOD_OUTPUT_PATH');
    $snapshot = is_string($snapshotPath) && $snapshotPath !== '' ? @file_get_contents($snapshotPath) : @shell_exec('lsmod 2>/dev/null');
    if (!is_string($snapshot)) return null;

    $loaded = [];
    $moduleSet = array_fill_keys($modules, true);
    foreach (preg_split('/\r?\n/', trim($snapshot)) ?: [] as $line) {
        $columns = preg_split('/\s+/', trim($line));
        if (!is_array($columns) || count($columns) < 3 || $columns[0] === 'Module' || !isset($moduleSet[$columns[0]])) continue;
        $loaded[$columns[0]] = ctype_digit($columns[2]) ? (int) $columns[2] : 0;
    }
    return $loaded;
}

function pmssEnsureDirtyFragBlacklist(callable $log, ?callable $runner = null): void { pmssApplyModprobeBlacklist(PMSS_KERNEL_HARDENING['dirtyfrag'], $log, $runner); }
function pmssEnsureAlgifAeadBlacklist(callable $log, ?callable $runner = null): void { pmssApplyModprobeBlacklist(PMSS_KERNEL_HARDENING['copyfail'], $log, $runner); }
