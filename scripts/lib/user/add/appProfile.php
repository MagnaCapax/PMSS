<?php
/** Creation-time app profile policy; no customer OpenClaw files are written by root. */
require_once __DIR__.'/../serviceLaunch.php';

/**
 * Load the root-owned skeleton installer's pure helpers only when the profile is used.
 * Never account-owned code; a missing skeleton must not break account creation.
 */
function pmssAddUserOpenClawInstallerLoad(): bool
{
    $installer = dirname(__DIR__, 4).'/etc/skel/bin/install-openclaw';
    if (!is_file($installer) || is_link($installer)) return false;
    require_once $installer;
    return defined('OC_REQUIRED_FILES') && function_exists('ocQuotaFileHeadroom');
}

/** Fail closed unless the profile has enough measured file quota and RAM for a fresh install. */
function pmssAddUserOpenClawGate(array $user, string $home, ?array $files): ?string
{
    if (($user['appProfile'] ?? '') !== 'openclaw') return 'profile not selected';
    if (!pmssAddUserOpenClawInstallerLoad()) return 'skeleton installer unavailable';
    if ((int) ($user['memory'] ?? 0) < 2048) return 'RAM below 2048 MiB';
    if (file_exists($home.'/.openclaw') || is_link($home.'/.openclaw')) return 'existing ~/.openclaw';
    if ($files === null || $files['used'] > $files['soft'] - OC_REQUIRED_FILES) return 'file quota has fewer than 60000 free files or is unavailable';
    if (!is_file($home.'/bin/install-openclaw')) return 'installer missing';
    return null;
}

/** Queue the same slice-contained, account-owned launch used for Pikkumies preinstalls. */
function pmssAddUserOpenClawPreinstall(array $user, string $home): void
{
    if (!pmssAddUserOpenClawInstallerLoad()) {
        logProvisionMessage('OpenClaw not preinstalled: skeleton installer unavailable');
        return;
    }
    $quota = pmssCommandCapture('quota -w -p -u '.escapeshellarg($user['name']), 10);
    $files = $quota['rc'] === 0 ? ocQuotaFileHeadroom($quota['stdout']) : null;
    $reason = pmssAddUserOpenClawGate($user, $home, $files);
    if ($reason !== null) {
        logProvisionMessage('OpenClaw not preinstalled: '.$reason);
        return;
    }
    runProvisionStep('Queue OpenClaw preinstall', pmssAddUserOpenClawPreinstallCommand($user['name']));
}

/** Keep background scope placement and account-local logging reviewable without launching. */
function pmssAddUserOpenClawPreinstallCommand(string $username): string
{
    $launch = pmssBuildUserServiceShellCommand($username,
        'umask 077; php "$HOME/bin/install-openclaw" install --yes >"$HOME/.install-openclaw.log" 2>&1', true);
    return 'nohup sh -c '.escapeshellarg($launch).' >/dev/null 2>&1 &';
}
