<?php
/** Nginx global setup and per-user render context. @license GPL-3.0-only */

require_once __DIR__.'/templates.php';
require_once __DIR__.'/pikkumiesUi.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../configBackups.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/setupFilesystem.php';
require_once __DIR__.'/setupTls.php';
require_once __DIR__.'/setupDefaultSite.php';
require_once __DIR__.'/setupAccountUi.php';

/** Prepare global configs in their existing order and return the stable render context. */
function pmssCreateNginxConfigSetup(): array
{
    $ctx = [
        'userTemplate' => @file_get_contents('/etc/seedbox/config/template.nginx-user'),
        'suspendedTemplate' => @file_get_contents('/etc/seedbox/config/template.nginx-user-suspended'),
        'movedTemplate' => @file_get_contents('/etc/seedbox/config/template.nginx-user-moved'),
        'movedNoticeTemplate' => @file_get_contents('/etc/seedbox/config/template.moved.notice.html'),
    ];
    foreach (['/etc/nginx', '/etc/nginx/sites-available', '/etc/nginx/sites-enabled'] as $path) {
        pmssCreateNginxConfigSetupDirectory($path);
    }
    foreach ([
        '/etc/seedbox/config/template.nginx-conf' => '/etc/nginx/nginx.conf',
        '/etc/seedbox/config/template.nginx-proxy_params' => '/etc/nginx/proxy_params',
        '/etc/seedbox/config/template.nginx-webdav_proxy_params' => '/etc/nginx/webdav_proxy_params',
    ] as $templatePath => $targetPath) {
        pmssBackupCriticalConfig('nginx', $targetPath);
        pmssCreateNginxConfigSetupCopy($templatePath, $targetPath);
    }

    $serverHostname = pmssHostnameRead();
    // Keep the first hostname token and its original case for certificate paths.
    $serverHostname = (string) (pmssConfigLineColumns($serverHostname, 0, [])[0] ?? $serverHostname);
    $subdomainBase = strtolower($serverHostname);
    $subdomainEnabled = pmssNginxUserHostIsValidFqdn($subdomainBase);
    $subdomainConfigDir = '/etc/nginx/conf.d';
    $defaultSite = @file_get_contents('/etc/seedbox/config/template.nginx-site-default');
    $ssl = pmssNginxConfigSetupSsl($serverHostname);
    $nginxSslBlock = trim((string) $ssl);
    if ($nginxSslBlock !== '') $nginxSslBlock .= "\n";
    pmssNginxConfigSetupDefaultSite($defaultSite, $ssl, $subdomainEnabled, $subdomainBase);

    pmssCreateNginxConfigSetupDirectory('/etc/nginx/ssl');
    if (!file_exists('/etc/nginx/ssl/nginx.crt')) {
        $command = pmssNginxConfigSelfSignedCertCommand($serverHostname);
        if ($command !== null) @passthru($command);
    }
    // Existing directories must receive their mode on every run too.
    if (!pmssEnsureSafeDir('/etc/nginx/users', 0751)) {
        fwrite(STDERR, "Could not apply mode 0751 to /etc/nginx/users\n");
    }
    if ($subdomainEnabled) {
        pmssCreateNginxConfigSetupDirectory($subdomainConfigDir);
    } elseif ($subdomainBase !== '') {
        fwrite(STDERR, "Skipping nginx subdomain vhosts (invalid hostname: {$subdomainBase})\n");
    }
    pmssNginxConfigSetupAccountUi($subdomainEnabled, $nginxSslBlock, $subdomainConfigDir);

    $templates = pmssNginxUserSubdomainTemplates();
    return $ctx + [
        'subdomainEnabled' => $subdomainEnabled,
        'subdomainBase' => $subdomainBase,
        'subdomainConfigDir' => $subdomainConfigDir,
        'nginxUsersDir' => '/etc/nginx/users',
        'homeBase' => '/home',
        'runtimePortDir' => '/etc/seedbox/runtime/ports',
        'billingPublicDir' => '/var/lib/pmss/public/billing',
        'nginxSslBlock' => $nginxSslBlock,
        'publicSubdomainTemplate' => $templates['public'],
        'publicSuspendedTemplate' => $templates['publicSuspended'],
        'publicMovedTemplate' => $templates['publicMoved'],
    ];
}
