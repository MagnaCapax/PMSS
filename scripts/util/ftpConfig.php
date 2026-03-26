#!/usr/bin/env php
<?php
/**
 * Render and apply the ProFTPD configuration using project templates.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

foreach (['../lib/update.php', '../lib/update/runtime/commands.php', '../lib/update/distro.php', '../lib/configBackups.php'] as $relativePath) {
    require_once __DIR__.'/'.$relativePath;
}

logMessage('Making ProFTPD configuration');

$configTemplate = @file_get_contents('/etc/seedbox/config/template.proftpd');
$hostnameRaw    = @file_get_contents('/etc/hostname');

if ($configTemplate === false || $hostnameRaw === false) {
    logMessage('No data, hostname or config template is empty!');
    exit(1);
}

$proftpdDir = '/etc/proftpd';
if (!is_dir($proftpdDir)) {
    logMessage("Skipping ProFTPD configuration (directory {$proftpdDir} missing).");
    exit(0);
}

$hostname = preg_replace('/[^a-z0-9.-]/', '', strtolower(trim($hostnameRaw)));
$hostname = $hostname === '' ? 'localhost' : $hostname;
$detected = \pmssDetectDistro();
$distroVersion = (int) ($detected['version'] ?? 0);
// Debian 10's proftpd-mod-crypto may not support TLSv1.3 → restrict to TLSv1.2 there.
$tlsProtocol = $distroVersion > 0 && $distroVersion <= 10
    ? '    TLSProtocol                   TLSv1.2'
    : '    TLSProtocol                   TLSv1.2 TLSv1.3';

$tlsBlock = '';
$candidates = ["/etc/letsencrypt/live/{$hostname}"];
if (strpos($hostname, '.') !== false) {
    [, $domain] = explode('.', $hostname, 2);
    $candidates[] = "/etc/letsencrypt/live/*.{$domain}";
}
$candidates[] = '/etc/seedbox/config/ssl/proftpd';

foreach ($candidates as $base) {
    if (!file_exists($base.'/cert.pem') || !file_exists($base.'/privkey.pem') || !file_exists($base.'/fullchain.pem')) {
        continue;
    }

    $tlsBlock = implode("\n", [
        '    TLSEngine                     on',
        '    TLSLog                        /var/log/proftpd/tls.log',
        $tlsProtocol,
        '    TLSCipherSuite                HIGH:!aNULL:!MD5:!3DES',
        '    TLSOptions                    NoSessionReuseRequired',
        '    TLSRenegotiate                none',
        '    TLSRSACertificateFile         "'.$base.'/cert.pem"',
        '    TLSRSACertificateKeyFile      "'.$base.'/privkey.pem"',
        '    TLSCACertificateFile          "'.$base.'/fullchain.pem"',
        '    TLSVerifyClient               off',
        '    TLSRequired                   off',
    ]);
    break;
}

$rendered = str_replace(
    ['%SERVERNAME%', '%TLS_CONFIGURATION%'],
    [$hostname, $tlsBlock],
    $configTemplate
);

if ($tlsBlock === '') { $rendered = preg_replace('#\n?<IfModule mod_tls\\.c>.*?</IfModule>#s', '', $rendered); }

foreach (['/var/log/proftpd', '/var/run/proftpd'] as $path) {
    if (!pmssDirEnsureExists($path, 0750)) {
        logMessage("Warning: Unable to create {$path}");
        continue;
    }

    @chmod($path, 0750);
    @chown($path, 'proftpd');
    @chgrp($path, 'nogroup');
}

pmssBackupCriticalConfig('proftpd', '/etc/proftpd/proftpd.conf');
if (@file_put_contents('/etc/proftpd/proftpd.conf', $rendered) === false) {
    logMessage('Failed to write /etc/proftpd/proftpd.conf');
    exit(1);
}
logMessage('Wrote /etc/proftpd/proftpd.conf');

if (
    pmssCommandPath('proftpd') !== ''
    && runStep('Validating ProFTPD configuration', 'proftpd -t -c /etc/proftpd/proftpd.conf') !== 0
) {
    logMessage('[WARN] ProFTPD config test failed; skipping restart to avoid stopping a working daemon');
    exit(0);
}

if (is_dir('/run/systemd/system')) {
    runStep('Restarting ProFTPD (systemd)', 'systemctl restart proftpd');
} elseif (file_exists('/etc/init.d/proftpd')) {
    runStep('Restarting ProFTPD (sysvinit)', '/etc/init.d/proftpd restart');
} else {
    logMessage('ProFTPD service manager not found; skipped restart');
}
