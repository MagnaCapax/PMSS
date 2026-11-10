<?php
/** Creation-time Hermes preinstall; reuse the account installer without changing its policy. */
require_once __DIR__.'/../serviceLaunch.php';
require_once __DIR__.'/../../runtime/filesystem.php';
require_once __DIR__.'/../userConfigStore.php';

/** Load only root-owned skeleton definitions for quota and panel URL validation, never customer PHP. */
function pmssAddUserHermesInstallerLoad(): bool
{
    $skeleton = dirname(__DIR__, 4).'/etc/skel/bin/';
    foreach (['install-hermes', 'pmssAccountAgent.php', 'pmssHermesRuntime.php', 'pmssHermesRelay.php'] as $file) {
        if (!is_file($skeleton.$file) || is_link($skeleton.$file)) return false;
    }
    require_once $skeleton.'pmssHermesRuntime.php';
    return defined('HERMES_REQUIRED_FILES') && function_exists('pmssAgentQuotaHeadroom') && function_exists('pmssHermesPanelUrl');
}

/** A missing gate skips this optional app; automatic installs need the installer's measured file count. */
function pmssAddUserHermesGate(array $user, string $home, ?array $files, array $ops = []): ?string
{
    if (!pmssAddUserHermesInstallerLoad()) return 'skeleton installer unavailable';
    if ((int) ($user['memory'] ?? 0) < pmssUserHermesMinRamMiB()) return 'RAM below '.pmssUserHermesMinRamMiB().' MiB';
    if (pmssPathExistsOrLink($home.'/.hermes') || pmssPathExistsOrLink($home.'/.local/bin/hermes')) return 'existing Hermes install';
    if ($files === null || $files['used'] > $files['soft'] - HERMES_REQUIRED_FILES)
        return 'file quota has fewer than '.HERMES_REQUIRED_FILES.' free files or is unavailable';
    $installer = $home.'/bin/install-hermes';
    if (!is_file($installer) || is_link($installer) || !is_executable($installer)) return 'installer missing or unsafe';
    $name = (string) ($user['name'] ?? basename($home));
    try {
        pmssHermesPanelUrl($name, $ops['host'] ?? strtolower(trim((string) @shell_exec('/bin/hostname -f'))));
    } catch (RuntimeException $error) { return 'panel hostname or account unavailable'; }
    $read = $ops['read'] ?? static function ($path) { return pmssReadRegularFileContentsVerified($path, 0, 1048576); };
    $portRead = $ops['port'] ?? 'pmssReadRegularFileNetworkPort';
    $port = $portRead('/etc/seedbox/runtime/ports/media-stack-hermes-'.$name);
    if ($port === null || $port < 1024) return 'reserved Hermes port unavailable';
    if (strpos((string) $read($home.'/.lighttpd.conf'), '^/user-'.$name.'/hermes/') === false
        || strpos((string) $read('/etc/nginx/users/'.$name), '/user-'.$name.'/hermes/') === false)
        return 'Hermes panel route unavailable; run a PMSS user update';
    return null;
}

/** Queue the existing locked installer as the account, inside its resource slice. */
function pmssAddUserHermesSwitch(bool $enabled, array $user, string $home, array $ops = []): array
{
    if (!$enabled) return ['message' => 'off; preinstall skipped'];
    if (!pmssAddUserHermesInstallerLoad()) return ['message' => 'preinstall skipped: skeleton installer unavailable'];
    $quota = pmssCommandCapture('quota -w -p -u '.escapeshellarg($user['name']), 10);
    $files = $quota['rc'] === 0 ? pmssAgentQuotaHeadroom($quota['stdout']) : null;
    $reason = pmssAddUserHermesGate($user, $home, $files, $ops);
    if ($reason !== null) return ['message' => 'preinstall skipped: '.$reason];
    // No new home files: status, private credentials and dashboard defaults belong to the existing installer.
    $launch = pmssBuildUserServiceShellCommand($user['name'],
        'umask 077; exec "$HOME/bin/install-hermes" install </dev/null', true);
    return ['message' => 'preinstall queued', 'queue' => ['Queue Hermes preinstall',
        'nohup sh -c '.escapeshellarg($launch).' >/dev/null 2>&1 &']];
}
