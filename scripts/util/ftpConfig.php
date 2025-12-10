#!/usr/bin/php
<?php
/**
 * Render and apply the ProFTPD configuration using project templates.
 */

require_once __DIR__.'/../lib/update/logging.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';
require_once __DIR__.'/../lib/update/distro.php';

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

$hostname = sanitizeHostname($hostnameRaw);
$distroVersion = 0;
$detected = \pmssDetectDistro();
if (is_array($detected) && isset($detected['version'])) {
    $distroVersion = (int) $detected['version'];
}

$tlsBlock = buildTlsConfiguration($hostname, $distroVersion);

$rendered = str_replace(
    ['%SERVERNAME%', '%TLS_CONFIGURATION%'],
    [$hostname, $tlsBlock],
    $configTemplate
);

if ($tlsBlock === '') {
    $rendered = preg_replace('#\n?<IfModule mod_tls\\.c>.*?</IfModule>#s', '', $rendered);
}

$logDir = '/var/log/proftpd';
$runDir = '/var/run/proftpd';

if (!is_dir($logDir) && !@mkdir($logDir, 0750, true)) {
    logMessage("Warning: Unable to create {$logDir}");
}
if (!is_dir($runDir) && !@mkdir($runDir, 0750, true)) {
    logMessage("Warning: Unable to create {$runDir}");
}

if (@file_put_contents('/etc/proftpd/proftpd.conf', $rendered) === false) {
    logMessage('Failed to write /etc/proftpd/proftpd.conf');
    exit(1);
}
logMessage('Wrote /etc/proftpd/proftpd.conf');

if (is_dir('/run/systemd/system')) {
    runStep('Restarting ProFTPD (systemd)', 'systemctl restart proftpd');
} elseif (file_exists('/etc/init.d/proftpd')) {
    runStep('Restarting ProFTPD (sysvinit)', '/etc/init.d/proftpd restart');
} else {
    logMessage('ProFTPD service manager not found; skipped restart');
}

/**
 * Normalise the hostname for template substitution.
 */
function sanitizeHostname(string $raw): string
{
    $hostname = strtolower(trim($raw));
    $hostname = preg_replace('/[^a-z0-9.-]/', '', $hostname);
    return $hostname === '' ? 'localhost' : $hostname;
}

/**
 * Build the TLS configuration block when certificates are available.
 */
function buildTlsConfiguration(string $hostname, int $distroVersion = 0): string
{
    $candidates = [];
    $trimmed = trim($hostname);
    if ($trimmed !== '') {
        $candidates[] = "/etc/letsencrypt/live/{$trimmed}";
        if (strpos($trimmed, '.') !== false) {
            [, $domain] = explode('.', $trimmed, 2);
            $candidates[] = "/etc/letsencrypt/live/*.{$domain}";
        }
    }
    $candidates[] = '/etc/seedbox/config/ssl/proftpd';

    foreach ($candidates as $base) {
        if (file_exists($base.'/cert.pem') && file_exists($base.'/privkey.pem') && file_exists($base.'/fullchain.pem')) {
            // Debian 10's proftpd-mod-crypto may not support TLSv1.3 → restrict to TLSv1.2 there.
            $tlsProtocol = '    TLSProtocol                   TLSv1.2 TLSv1.3';
            if ($distroVersion > 0 && $distroVersion <= 10) {
                $tlsProtocol = '    TLSProtocol                   TLSv1.2';
            }

            return implode("\n", [
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
        }
    }

    return '';
}
