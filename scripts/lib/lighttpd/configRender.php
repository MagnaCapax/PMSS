<?php
/**
 * Template rendering helpers for per-user lighttpd configuration.
 *
 * @license GPL-3.0-only
 */

function pmssRenderLighttpdConfig(string $template, string $user, int $serverPort, int $rclonePort, int $qbittorrentPort, array $resources): string
{
    $webdavWwwPolicy = pmssWebdavWwwPolicyBlock($user);
    $config = str_replace(
        array("##username", "##serverPort", "##rclonePort", "##qbittorrentPort", "##PMSS_WEBDAV_WWW_POLICY##"),
        array($user, $serverPort, $rclonePort, $qbittorrentPort, $webdavWwwPolicy),
        $template
    );

    $config = preg_replace(
        ['/("max-procs"\\s*=>\\s*)[0-9]+/', '/("PHP_FCGI_CHILDREN"\\s*=>\\s*")[0-9]+(")/'],
        ['${1}'.$resources['maxProcs'], '${1}'.$resources['children'].'${2}'],
        $config,
        1
    );

    return pmssClampLighttpdBandwidthLimits($config);
}

function pmssClampLighttpdBandwidthLimits(string $config): string
{
    // lighttpd enforces uint16 for kbytes-per-second; overflow breaks startup on newer releases.
    $pattern = '/^(\\s*(?:connection|server)\\.kbytes-per-second\\s*=\\s*)(\\d+)(\\s*(?:#.*)?)$/m';
    $clamped = preg_replace_callback(
        $pattern,
        function (array $matches): string {
            $value = (int)$matches[2];
            if ($value > 65535) {
                $value = 0;
            }
            return $matches[1].$value.$matches[3];
        },
        $config
    );

    return is_string($clamped) ? $clamped : $config;
}

function pmssWebdavWwwPolicyBlock(string $user): string
{
    // Defense-in-depth: validate username even though upstream should have validated.
    // Reject invalid usernames to prevent regex injection or path traversal in lighttpd config.
    // Valid PMSS usernames: ^[a-z][a-z0-9]{0,7}$ (1-8 chars, starts with letter, alphanumeric).
    if (!pmssUsernameIsValid($user)) {
        // Return safe empty block rather than generating config with untrusted input.
        // Log a warning so operators can investigate how invalid input reached here.
        error_log("pmssWebdavWwwPolicyBlock: rejected invalid username: " . substr($user, 0, 20));
        return '# WebDAV www policy skipped: invalid username';
    }

    // Default: keep ~/www read-only over WebDAV to prevent users from breaking the web stack.
    // Allow writing to ~/www/public by default, and allow full ~/www write if the user opts in.
    $marker = "/home/{$user}/.lighttpd/webdav.www-writable";
    if (file_exists($marker)) {
        return <<<LIGHTTPD
\$HTTP["url"] =~ "^/webdav-{$user}/www(\$|/)" {
    webdav.is-readonly = "disable"
}
LIGHTTPD;
    }

    return <<<LIGHTTPD
\$HTTP["url"] =~ "^/webdav-{$user}/www(\$|/)" {
    webdav.is-readonly = "enable"
}
\$HTTP["url"] =~ "^/webdav-{$user}/www/public(\$|/)" {
    webdav.is-readonly = "disable"
}
LIGHTTPD;
}

function pmssLighttpdWebdavModulePresent(): bool
{
    // Debian packages typically install into /usr/lib/lighttpd or /usr/lib/*/lighttpd.
    return !empty(glob('/usr/lib*/lighttpd/mod_webdav.so'));
}

function pmssStripLighttpdWebdavConfig(string $template): string
{
    // Strip the managed WebDAV block (if present) to keep lighttpd start-safe on hosts
    // where the module is missing or was manually removed.
    $template = preg_replace(
        '/^\\s*#\\s*PMSS_WEBDAV_BEGIN\\s*$.*^\\s*#\\s*PMSS_WEBDAV_END\\s*$\\s*/ms',
        '',
        $template
    );

    // Comment out the module line if present.
    $template = preg_replace(
        '/^(\\s*)\"mod_webdav\",\\s*$/m',
        '${1}#"mod_webdav",',
        $template,
        1
    );

    // Remove placeholder that would otherwise leak into lighttpd.conf.
    $template = str_replace('##PMSS_WEBDAV_WWW_POLICY##', '', $template);

    return (string)$template;
}
