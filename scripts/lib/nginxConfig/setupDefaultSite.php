<?php
/** Default-site rendering and publication behind the global setup facade. */
/**
 * Ensure the default nginx site defines default_server on its listen directives.
 *
 * Older hosts may still carry stale site templates without default_server, which
 * causes nginx to treat the first loaded vhost as the implicit default (conf.d
 * loads before sites-enabled by default).
 * Regex failures retain the last intact config/line rather than emptying it.
 */
function pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer(string $config): string
{
    $config = preg_replace_callback('/^(\\s*listen\\s+(80|443)\\b)([^;]*)(;.*)$/m', static function (array $match) {
        if ($match[2] === '443' && preg_match('/\\bssl\\b/', $match[3]) !== 1) {
            return $match[0];
        }
        if (preg_match('/\\bdefault_server\\b/', $match[3]) !== 0) {
            return $match[0];
        }
        return $match[1].rtrim($match[3]).' default_server'.$match[4];
    }, $config) ?? $config;

    return $config;
}

/** Preserve backup/write/link ordering, including enabled regular-file copies. */
function pmssNginxConfigSetupDefaultSite($config, $ssl, bool $subdomainEnabled, string $subdomainBase): void
{
    if ($config === false) return;
    $config = pmssNginxConfigEnsureSiteDefaultDefinesDefaultServer((string) $config);
    // Base-host requests must reach the main vhost's account locations.
    if ($subdomainEnabled) {
        $config = str_replace('server_name localhost;', 'server_name localhost '.$subdomainBase.';', $config);
    }
    $config = str_replace('||SSL_SETTINGS_CONFIGURED_HERE||', (string) $ssl, $config);
    pmssBackupCriticalConfig('nginx', '/etc/nginx/sites-available/default');
    pmssCreateNginxConfigSetupWrite('/etc/nginx/sites-available/default', $config);
    $enabledDefault = '/etc/nginx/sites-enabled/default';
    if (!file_exists($enabledDefault)) {
        if (@symlink('/etc/nginx/sites-available/default', $enabledDefault) === false) {
            pmssCreateNginxConfigSetupWrite($enabledDefault, $config);
        }
    } elseif (!is_link($enabledDefault)) {
        pmssBackupCriticalConfig('nginx', $enabledDefault);
        pmssCreateNginxConfigSetupWrite($enabledDefault, $config);
    }
}
