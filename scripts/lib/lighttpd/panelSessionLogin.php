<?php
/**
 * Opt-in panel session-cookie lighttpd integration helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/identity.php';
require_once __DIR__.'/userFileWrite.php';

const PMSS_LIGHTTPD_PANEL_SESSION_GATE_SOURCE = __DIR__.'/panelSessionGate.lua';
const PMSS_LIGHTTPD_PANEL_SESSION_GATE_BASENAME = 'panelSessionGate.lua';
const PMSS_LIGHTTPD_PANEL_SESSION_MAGNET_MODULE_GLOBS = [
    '/usr/lib/lighttpd/mod_magnet.so',
    '/usr/lib/*/lighttpd/mod_magnet.so',
    '/usr/lib*/lighttpd/mod_magnet.so',
];

function pmssLighttpdPanelSessionGatePath(string $homeDir): string
{
    return rtrim($homeDir, '/').'/.lighttpd/'.PMSS_LIGHTTPD_PANEL_SESSION_GATE_BASENAME;
}

function pmssLighttpdPanelSessionGateFileUsable(string $path): bool
{
    return $path !== '' && is_file($path) && !is_link($path);
}

function pmssLighttpdPanelSessionGateDeploy(string $user, string $homeDir): bool
{
    if (!pmssUsernameIsValid($user) || !is_dir($homeDir) || is_link($homeDir)) {
        return false;
    }

    $source = PMSS_LIGHTTPD_PANEL_SESSION_GATE_SOURCE;
    if (!is_file($source) || is_link($source)) {
        return false;
    }
    $content = @file_get_contents($source);
    if (!is_string($content) || $content === '') {
        return false;
    }

    $target = pmssLighttpdPanelSessionGatePath($homeDir);
    return pmssWriteUserFile($target, $content, $user, 0600)
        && pmssLighttpdPanelSessionGateFileUsable($target);
}

function pmssLighttpdPanelSessionMagnetModuleLoadable(?array $modulePaths = null): bool
{
    if ($modulePaths === null) {
        $modulePaths = [];
        foreach (PMSS_LIGHTTPD_PANEL_SESSION_MAGNET_MODULE_GLOBS as $glob) {
            foreach (glob($glob) ?: [] as $path) {
                $modulePaths[] = $path;
            }
        }
    }

    foreach (array_unique($modulePaths) as $path) {
        if (is_string($path) && is_file($path) && !is_link($path)) {
            return true;
        }
    }

    return false;
}

function pmssLighttpdPanelSessionLoginOptions(string $user, string $homeDir, bool $enabled, ?array $modulePaths = null): array
{
    $gatePath = pmssLighttpdPanelSessionGatePath($homeDir);

    return [
        'enabled' => $enabled && pmssUsernameIsValid($user),
        'moduleLoadable' => pmssLighttpdPanelSessionMagnetModuleLoadable($modulePaths),
        'gatePath' => $gatePath,
        'gateExists' => pmssLighttpdPanelSessionGateFileUsable($gatePath),
    ];
}

function pmssLighttpdPanelSessionLoginShouldEmit(array $options): bool
{
    $gatePath = isset($options['gatePath']) && is_string($options['gatePath']) ? $options['gatePath'] : '';
    $gateExists = array_key_exists('gateExists', $options)
        ? (bool) $options['gateExists']
        : pmssLighttpdPanelSessionGateFileUsable($gatePath);

    return !empty($options['enabled'])
        && !empty($options['moduleLoadable'])
        && $gatePath !== ''
        && $gateExists;
}

function pmssLighttpdPanelSessionLoginFallbackReason(array $options): string
{
    if (empty($options['enabled'])) {
        return '';
    }
    if (empty($options['moduleLoadable'])) {
        return 'mod_magnet module is unavailable';
    }
    if (empty($options['gateExists'])) {
        return 'panel session Lua gate is not deployed';
    }

    return '';
}

function pmssLighttpdPanelSessionConfigString(string $value): string
{
    return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
}

function pmssLighttpdPanelSessionModulesEnsure(string $config): string
{
    if (strpos($config, '"mod_magnet"') !== false) {
        return $config;
    }

    $updated = preg_replace_callback(
        '/^(\s*)"mod_auth",\s*$/m',
        static function (array $matches): string {
            return $matches[1].'"mod_magnet",'."\n".$matches[0];
        },
        $config,
        1,
        $count
    );

    return $count === 1 && is_string($updated) ? $updated : $config;
}

function pmssLighttpdPanelSessionMagnetBlock(string $user, string $gatePath): string
{
    $gatePathConfig = pmssLighttpdPanelSessionConfigString($gatePath);

    return <<<LIGHTTPD
# PMSS panel session-cookie login (opt-in; cookie OR Basic, Refs #855).
\$HTTP["url"] =~ "^/user-{$user}(\$|/)" {
    auth.extern-authn = "enable"
    magnet.attract-raw-url-to = ( {$gatePathConfig} )
}
LIGHTTPD;
}

function pmssLighttpdPanelSessionConfigApply(string $config, string $user, array $options): string
{
    if (!pmssLighttpdPanelSessionLoginShouldEmit($options) || !pmssUsernameIsValid($user)) {
        return $config;
    }

    $withModule = pmssLighttpdPanelSessionModulesEnsure($config);
    if (strpos($withModule, '"mod_magnet"') === false) {
        return $config;
    }

    $gatePath = (string) $options['gatePath'];
    if (strpos($withModule, 'magnet.attract-raw-url-to') !== false) {
        return $withModule;
    }

    return rtrim($withModule)."\n\n".pmssLighttpdPanelSessionMagnetBlock($user, $gatePath)."\n";
}
