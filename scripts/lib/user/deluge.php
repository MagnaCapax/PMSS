<?php
/**
 * Deluge configuration helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../update/runtime/commands.php';
pmssRequireRelativeFiles(__DIR__, ['../update/distro.php', '../lighttpd/userFileWrite.php', 'traffic.php', 'delugeManagedConfig.php', 'passwords.php', '../portManager.php']);

/**
 * Pick the Deluge core template path for the active Debian release.
 *
 * Debian 12+ ships libtorrent 2.0, which removed the legacy cache_*
 * settings. Older releases keep the cache directives enabled.
 *
 * @param array{name?:string,version?:int,codename?:string}|null $distro
 */
function pmssDelugeCoreTemplatePath(?array $distro = null): string
{
    $configDir = pmssResolvePathFromEnv('PMSS_SEEDBOX_CONFIG_DIR', '/etc/seedbox/config');

    return (int) (($distro['version'] ?? 0)) >= 12
        ? $configDir.'/template.deluge.core.nocache.conf'
        : $configDir.'/template.deluge.core.conf';
}

/**
 * Resolve an auxiliary Deluge template under the configured seedbox config dir.
 */
function pmssDelugeTemplatePath(string $templateName): string
{
    return pmssResolvePathFromEnv('PMSS_SEEDBOX_CONFIG_DIR', '/etc/seedbox/config').'/'.$templateName;
}

/**
 * Read a required Deluge template and emit a structured warning on failure.
 */
function pmssDelugeTemplateRead(string $templatePath, string $label): string
{
    $template = @file_get_contents($templatePath);
    if (is_string($template) && $template !== '') {
        return $template;
    }

    pmssLogStatus('WARN', sprintf(
        'Skipping Deluge %s template because it is %s: %s',
        $label,
        file_exists($templatePath) ? 'empty' : 'missing',
        $templatePath
    ), 1);
    return '';
}

/**
 * Render Deluge core.conf from the PMSS template and runtime values.
 *
 * @param array{name:string,memory:int|float} $user
 * @param array{name?:string,version?:int,codename?:string}|null $distro
 */
function pmssDelugeRenderCoreConfig(array $user, int $delugePort, string $uploadThrottle, ?array $distro = null): string
{
    $username = (string) $user['name'];
    $template = pmssDelugeTemplateRead(pmssDelugeCoreTemplatePath($distro ?? pmssDetectDistro()), 'core');
    if ($template === '') {
        return '';
    }

    return str_replace(
        ['##USERNAME##', '##CACHE', '##DAEMONPORT', '##UPLOAD_THROTTLE##'],
        [$username, (int) ($user['memory'] * 1024 / 16), $delugePort, $uploadThrottle],
        $template
    );
}

/**
 * Persist a Deluge config file through the shared symlink-safe writer.
 */
function pmssDelugeConfigFileWrite(string $path, string $content, string $label, ?string $owner = null): bool
{
    $existing = @lstat($path);
    if ($owner !== null && is_array($existing)
        && (($existing['mode'] & 0170000) !== 0100000 || $existing['nlink'] !== 1)) {
        pmssLogStatus('WARN', sprintf('Refusing Deluge %s config path: %s', $label, $path), 1);
        return false;
    }
    $mode = is_array($existing) ? ($existing['mode'] & 0777) : 0644;
    if ($owner !== null
        ? pmssWriteUserFile($path, $content, $owner, $mode)
        : pmssReplaceUserFilePreservingMetadata($path, $content, 0644)) {
        return true;
    }

    pmssLogStatus('WARN', sprintf('Failed to write Deluge %s config: %s', $label, $path), 1);
    return false;
}

function pmssDelugeWebPortEnsure(string $username, string $home, int $delugePort): ?int
{
    $webPortFile = $home.'/.delugeWebPort';
    $existingWebPort = pmssReadRegularFileNetworkPort($webPortFile, 1024, 65000);
    if ($existingWebPort !== null) {
        pmssPortManagerAssignServicePort($username, 'deluge-web', $existingWebPort);
        return $existingWebPort;
    }

    $preferredWebPort = $delugePort + 1;
    $preferredWebPort = pmssNetworkPortInRange($preferredWebPort, PMSS_PORT_MANAGER_MIN_PORT, PMSS_PORT_MANAGER_MAX_PORT)
        ? $preferredWebPort
        : null;
    $webPort = pmssPortManagerAssignServicePort($username, 'deluge-web', $preferredWebPort);
    if ($webPort === null || !pmssWriteUserFile($webPortFile, (string) $webPort, $username, 0644)) {
        return null;
    }

    return $webPort;
}

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

    if (!file_exists($configDir)) {
        runStep('Creating Deluge config dir', pmssBuildUserShellCommand($username, 'mkdir -p -- '.escapeshellarg($configDir)));
    }
    $ownedDirs = [
        'Deluge unfinished' => $unfinishedDir,
        'Deluge session'    => $sessionDir,
    ];
    foreach ($ownedDirs as $label => $dir) {
        if (!file_exists($dir)) {
            runStep('Creating '.$label.' dir', pmssBuildUserShellCommand($username, 'mkdir -p -- '.escapeshellarg($dir)));
        }
    }

    $scgiPort    = $configuration['config']['scgiPort'] ?? 5000;
    $existingPort = file_exists("$home/.delugePort") ? (int) @file_get_contents("$home/.delugePort") : 0;
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

    pmssDelugeConfigFileWrite("$configDir/core.conf", $coreConfig, 'core', $username);
    pmssDelugeConfigFileWrite("$configDir/hostlist.conf", str_replace('##DAEMONPORT', $delugePort, $hostlistTemplate), 'hostlist', $username);
    if (!file_exists("$configDir/hostlist.conf.1.2")) {
        runStep('Creating Deluge hostlist compatibility link', pmssBuildUserShellCommand($username,
            'ln -s -- '.escapeshellarg("$configDir/hostlist.conf").' '.escapeshellarg("$configDir/hostlist.conf.1.2")));
    }

    $webConfPath = "$configDir/web.conf";
    $existingWebConfig = @file_get_contents($webConfPath);
    $webConfig   = str_replace(['##WEBPORT', '##USER'], [$delugeWebPort, $username], $webTemplate);
    $webConfChanged = is_string($existingWebConfig) && $existingWebConfig !== $webConfig;
    pmssDelugeConfigFileWrite($webConfPath, $webConfig, 'web', $username);
    pmssWriteUserFile("$home/.delugePort", (string) $delugePort, $username, 0644);

    if (!file_exists("$configDir/auth")) {
        $authTemplate = pmssDelugeTemplatePath('template.deluge.auth');
        $authContent = @file_get_contents($authTemplate);
        if (!is_string($authContent)) {
            pmssLogStatus('WARN', 'Skipping Deluge auth template copy because the template is missing: '.$authTemplate, 1);
        } else {
            if (!pmssWriteUserFile("$configDir/auth", $authContent, $username, 0600)) {
                pmssLogStatus('WARN', 'Failed to write Deluge auth template: '.$configDir.'/auth', 1);
            }
        }
    }
    pmssEnsureDelugeServicePassword($username);

    // If the web config changed, restart deluge-web so base/port changes take effect.
    // Cron (checkDelugeInstances.php) will start it again when Deluge is enabled.
    if ($webConfChanged && file_exists("$home/.delugeEnable")) {
        runStep('Restarting Deluge Web UI (config changed)', sprintf(
            'killall -u %s -TERM deluge-web 2>/dev/null || true',
            escapeshellarg($username)
        ));
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

    if ($throttle === null) {
        $throttle = pmssReadTorrentThrottle($username);
    }
    $value = ($throttle !== null && $throttle > 0) ? (string) $throttle : '-1.0';
    $updated = preg_replace('/"max_upload_speed"\\s*:\\s*-?[0-9.]+/', '"max_upload_speed": '.$value, $config, 1, $count);

    return $count > 0
        && $updated !== null
        && $updated !== $config
        && pmssDelugeConfigFileWrite($configFile, $updated, 'upload throttle');
}
