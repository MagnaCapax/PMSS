<?php
/** Creation-time Hermes preinstall; reuse the account installer without changing its policy. */
require_once __DIR__.'/../serviceLaunch.php';

/** Load only the root-owned skeleton's pure quota parser, never customer PHP. */
function pmssAddUserHermesInstallerLoad(): bool
{
    $skeleton = dirname(__DIR__, 4).'/etc/skel/bin/';
    foreach (['install-hermes', 'pmssAccountAgent.php'] as $file) {
        if (!is_file($skeleton.$file) || is_link($skeleton.$file)) return false;
    }
    require_once $skeleton.'pmssAccountAgent.php';
    return function_exists('pmssAgentQuotaHeadroom');
}

/** A missing gate skips this optional app; automatic installs reserve 65000 file slots. */
function pmssAddUserHermesGate(array $user, string $home, ?array $files): ?string
{
    if (!pmssAddUserHermesInstallerLoad()) return 'skeleton installer unavailable';
    if ((int) ($user['memory'] ?? 0) < 1000) return 'RAM below 1000 MiB';
    if (file_exists($home.'/.hermes') || is_link($home.'/.hermes')
        || file_exists($home.'/.local/bin/hermes') || is_link($home.'/.local/bin/hermes')) return 'existing Hermes install';
    if ($files === null || $files['used'] > $files['soft'] - 65000) return 'file quota has fewer than 65000 free files or is unavailable';
    $installer = $home.'/bin/install-hermes';
    if (!is_file($installer) || is_link($installer) || !is_executable($installer)) return 'installer missing or unsafe';
    return null;
}

/** Queue the existing locked installer as the account, inside its resource slice. */
function pmssAddUserHermesSwitch(bool $enabled, array $user, string $home): array
{
    if (!$enabled) return ['message' => 'off; preinstall skipped'];
    if (!pmssAddUserHermesInstallerLoad()) return ['message' => 'preinstall skipped: skeleton installer unavailable'];
    $quota = pmssCommandCapture('quota -w -p -u '.escapeshellarg($user['name']), 10);
    $files = $quota['rc'] === 0 ? pmssAgentQuotaHeadroom($quota['stdout']) : null;
    $reason = pmssAddUserHermesGate($user, $home, $files);
    if ($reason !== null) return ['message' => 'preinstall skipped: '.$reason];
    // No new home files: status, private credentials and dashboard defaults belong to the existing installer.
    $launch = pmssBuildUserServiceShellCommand($user['name'],
        'umask 077; exec "$HOME/bin/install-hermes" install </dev/null', true);
    return ['message' => 'preinstall queued', 'queue' => ['Queue Hermes preinstall',
        'nohup sh -c '.escapeshellarg($launch).' >/dev/null 2>&1 &']];
}
