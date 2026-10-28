<?php
/** Creation-time OpenClaw switch; no customer OpenClaw files are written by root. */
require_once __DIR__.'/../serviceLaunch.php';

/**
 * Load the root-owned skeleton installer's pure helpers only when the profile is used.
 * Never account-owned code; a missing skeleton must not break account creation.
 */
function pmssAddUserOpenClawInstallerLoad(): bool
{
    $installer = dirname(__DIR__, 4).'/etc/skel/bin/install-openclaw';
    if (!is_file($installer) || is_link($installer)) return false;
    // The installer is a CLI script; some PHP builds echo its shebang line when it is included.
    ob_start();
    require_once $installer;
    ob_end_clean();
    return defined('OC_REQUIRED_FILES') && function_exists('ocQuotaFileHeadroom');
}

/** Fail closed unless the profile has enough measured file quota and RAM for a fresh install. */
function pmssAddUserOpenClawGate(array $user, string $home, ?array $files): ?string
{
    if (!pmssAddUserOpenClawInstallerLoad()) return 'skeleton installer unavailable';
    if ((int) ($user['memory'] ?? 0) < 2048) return 'RAM below 2048 MiB';
    if (file_exists($home.'/.openclaw') || is_link($home.'/.openclaw')) return 'existing ~/.openclaw';
    if ($files === null || $files['used'] > $files['soft'] - OC_REQUIRED_FILES) return 'file quota has fewer than 60000 free files or is unavailable';
    if (!is_file($home.'/bin/install-openclaw')) return 'installer missing';
    return null;
}

/** Plan the same slice-contained, account-owned launch used for Pikkumies preinstalls. */
function pmssAddUserOpenClawSwitch(bool $enabled, array $user, string $home): array
{
    if (!$enabled) return ['message' => 'off; preinstall skipped'];
    if (!pmssAddUserOpenClawInstallerLoad()) {
        return ['message' => 'preinstall skipped: skeleton installer unavailable'];
    }
    $quota = pmssCommandCapture('quota -w -p -u '.escapeshellarg($user['name']), 10);
    $files = $quota['rc'] === 0 && function_exists('ocQuotaFileHeadroom') ? ocQuotaFileHeadroom($quota['stdout']) : null;
    $reason = pmssAddUserOpenClawGate($user, $home, $files);
    if ($reason !== null) {
        return ['message' => 'preinstall skipped: '.$reason];
    }
    return ['message' => 'preinstall queued', 'queue' => ['Queue OpenClaw preinstall', pmssAddUserOpenClawPreinstallCommand($user['name'])]];
}

/**
 * Keep background scope placement and account-local logging reviewable without launching. The shell records its
 * pid and execs the installer, so the panel's status reader (userOpenClaw.php) sees this first install as "installing"
 * exactly as it sees an install started from the panel (same pid file, same /usr/bin/php command line).
 */
function pmssAddUserOpenClawPreinstallCommand(string $username): string
{
    $launch = pmssBuildUserServiceShellCommand($username,
        'umask 077; echo $$ >"$HOME/.install-openclaw-web.pid"; exec /usr/bin/php "$HOME/bin/install-openclaw" install --yes >"$HOME/.install-openclaw.log" 2>&1', true);
    return 'nohup sh -c '.escapeshellarg($launch).' >/dev/null 2>&1 &';
}
