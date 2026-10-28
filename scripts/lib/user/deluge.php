<?php
/**
 * Deluge provisioning controller; template and publication helpers live alongside it.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
require_once __DIR__.'/delugeTemplates.php';
require_once __DIR__.'/delugeProvisioning.php';
require_once __DIR__.'/delugePasswords.php';
pmssRequireRelativeFiles(__DIR__, ['../update/distro.php', '../lighttpd/userFileWrite.php', 'traffic.php', 'delugeManagedConfig.php', 'passwords.php', '../portManager.php']);

function userConfigureDeluge(array $user, array $configuration): void
{
    $username = $user['name'];
    $home = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home')."/{$username}";
    $configDir     = "$home/.config/deluge";
    $unfinishedDir = "$home/dataUnfinished";
    $sessionDir    = "$home/.sessionDeluge";

    // Refuse substituted or foreign-owned config roots before any setup step.
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($username) : false;
    if (!is_array($account) || !isset($account['uid'])) {
        pmssLogStatus('WARN', 'Skipping Deluge configuration: account lookup failed for '.$username, 1);
        return;
    }
    foreach (["$home/.config", $configDir] as $path) {
        clearstatcache(true, $path);
        $entry = @lstat($path);
        if (is_array($entry) && (($entry['mode'] & 0170000) !== 0040000 || $entry['uid'] !== $account['uid'])) {
            pmssLogStatus('WARN', 'Skipping Deluge configuration: unsafe directory '.$path, 1);
            return;
        }
    }

    $ownedDirs = [
        'Deluge config'     => $configDir,
        'Deluge unfinished' => $unfinishedDir,
        'Deluge session'    => $sessionDir,
    ];
    foreach ($ownedDirs as $label => $dir) {
        if (!file_exists($dir)) {
            runStep('Creating '.$label.' dir', pmssBuildUserShellCommand($username, 'mkdir -p -- '.escapeshellarg($dir)));
        }
    }

    $scgiPort    = $configuration['config']['scgiPort'] ?? 5000;
    $portContents = pmssReadRegularFileContentsVerified("$home/.delugePort", null, 16);
    $existingPort = $portContents !== null ? (int) $portContents : 0;
    $delugePort   = ($existingPort >= 1024 && $existingPort <= 65000) ? $existingPort : $scgiPort;
    $throttle = pmssReadTorrentThrottle($username);
    $uploadThrottle = ($throttle !== null && $throttle > 0) ? (string) $throttle : '-1.0';

    $coreConfig = pmssDelugeRenderCoreConfig($user, $delugePort, $uploadThrottle);
    $hostlistTemplate = pmssDelugeTemplateRead(pmssDelugeTemplatePath('template.deluge.hostlist.conf'), 'hostlist');
    $webTemplate = pmssDelugeTemplateRead(pmssDelugeTemplatePath('template.deluge.web.conf'), 'web');
    if ($coreConfig === '' || $hostlistTemplate === '' || $webTemplate === '') {
        pmssLogStatus('WARN', 'Skipping Deluge configuration update because one or more templates are unavailable', 1);
        return;
    }
    $delugeWebPort = pmssDelugeWebPortEnsure($username, $home, (int) $delugePort);
    if ($delugeWebPort === null) {
        pmssLogStatus('WARN', 'Skipping Deluge configuration update because no web port could be assigned', 1);
        return;
    }

    $authPath = "$configDir/auth";
    $localclientPassword = pmssDelugeAuthLocalclientPasswordRead($authPath);
    if ($localclientPassword !== '' && pmssDelugeLegacyLocalclientPassword($localclientPassword)) {
        if (!pmssDelugeCredentialReadyForStart($username)) {
            return;
        }
        $localclientPassword = pmssDelugeAuthLocalclientPasswordRead($authPath);
    }
    if ($localclientPassword === '') {
        $localclientPassword = pmssDelugeServicePasswordGenerate();
        // An absent localclient line must never discard customer-added logins.
        if (pmssPathExistsOrLink($authPath)) {
            $authWritten = pmssDelugeAuthWriteLocalclientPassword($authPath, $localclientPassword, $username);
        } else {
            $authTemplate = pmssDelugeTemplateRead(pmssDelugeTemplatePath('template.deluge.auth'), 'auth');
            $authWritten = $authTemplate !== '' && pmssWriteUserFile($authPath,
                str_replace('##LOCALCLIENT_PASSWORD##', $localclientPassword, $authTemplate), $username, 0600);
        }
        if (!$authWritten) {
            pmssLogStatus('WARN', 'Skipping Deluge configuration because auth could not be written', 1);
            return;
        }
    }

    pmssDelugeConfigFileWrite("$configDir/core.conf", $coreConfig, 'core', $username);
    pmssDelugeConfigFileWrite("$configDir/hostlist.conf", str_replace(
        ['##DAEMONPORT', '##LOCALCLIENT_PASSWORD##'], [$delugePort, $localclientPassword], $hostlistTemplate
    ), 'hostlist', $username, 0600);
    if (!file_exists("$configDir/hostlist.conf.1.2")) {
        runStep('Creating Deluge hostlist compatibility link', pmssBuildUserShellCommand($username,
            'ln -s -- '.escapeshellarg("$configDir/hostlist.conf").' '.escapeshellarg("$configDir/hostlist.conf.1.2")));
    }

    $webConfPath = "$configDir/web.conf";
    $existingWebConfig = pmssReadRegularFileContentsVerified($webConfPath, null, 1048576);
    $parsedWebConfig = is_string($existingWebConfig) ? pmssDelugeConfigDecode($existingWebConfig) : null;
    $salt = is_array($parsedWebConfig) ? ($parsedWebConfig['config']['pwd_salt'] ?? '') : '';
    if (!is_string($salt) || strlen($salt) !== 40 || !ctype_xdigit($salt)
        || pmssDelugeLegacySalt($salt)) {
        $salt = bin2hex(random_bytes(20));
    }
    $webConfig = str_replace(
        ['##WEBPORT', '##USER', '##PWD_SALT##', '##PWD_SHA1##'],
        [$delugeWebPort, $username, $salt, sha1($salt.$localclientPassword)], $webTemplate
    );
    $webConfChanged = is_string($existingWebConfig) && $existingWebConfig !== $webConfig;
    pmssDelugeConfigFileWrite($webConfPath, $webConfig, 'web', $username, 0600);
    pmssWriteUserFile("$home/.delugePort", (string) $delugePort, $username, 0644);

    pmssEnsureDelugeServicePassword($username);

    // If the web config changed, restart deluge-web so base/port changes take effect.
    // Cron (checkDelugeInstances.php) will start it again when Deluge is enabled.
    if ($webConfChanged && file_exists("$home/.delugeEnable")) {
        pmssTorrentClientNativeStop($username, 'deluge', 15, ['processName' => 'deluge-web']);
    }
}

/**
 * Update Deluge core.conf with the current upload throttle, returning true on change.
 */
function pmssDelugeApplyUploadThrottle(string $username, ?int $throttle = null): bool
{
    $configFile = pmssDelugeConfigPath($username);
    $config = pmssReadRegularFileContents($configFile);
    if ($config === null) {
        return false;
    }

    $throttle = $throttle ?? pmssReadTorrentThrottle($username);
    $value = ($throttle !== null && $throttle > 0) ? (string) $throttle : '-1.0';
    $updated = preg_replace('/"max_upload_speed"\\s*:\\s*-?[0-9.]+/', '"max_upload_speed": '.$value, $config, 1, $count);

    return $count > 0
        && $updated !== null
        && $updated !== $config
        && pmssDelugeConfigFileWrite($configFile, $updated, 'upload throttle');
}
