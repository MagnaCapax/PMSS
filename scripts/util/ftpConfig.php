#!/usr/bin/php
<?php
/**
 * Render and apply the ProFTPD configuration using project templates.
 */

echo date('Y-m-d H:i:s').': Making ProFTPd configuration' . "\n";

$configTemplate = @file_get_contents('/etc/seedbox/config/template.proftpd');
$hostnameRaw    = @file_get_contents('/etc/hostname');

if ($configTemplate === false || $hostnameRaw === false) {
    die('No data, hostname or config template is empty!');
}

$proftpdDir = '/etc/proftpd';
if (!is_dir($proftpdDir)) {
    fwrite(STDERR, "Skipping ProFTPD configuration (directory {$proftpdDir} missing).\n");
    return;
}

$hostname = sanitizeHostname($hostnameRaw);
$tlsBlock = buildTlsConfiguration($hostname);

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
    echo "Warning: Unable to create {$logDir}\n";
}
if (!is_dir($runDir) && !@mkdir($runDir, 0750, true)) {
    echo "Warning: Unable to create {$runDir}\n";
}

file_put_contents('/etc/proftpd/proftpd.conf', $rendered);

$rc = 0;
if (is_dir('/run/systemd/system')) {
    passthru('systemctl restart proftpd', $rc);
} elseif (file_exists('/etc/init.d/proftpd')) {
    passthru('/etc/init.d/proftpd restart', $rc);
}

if (($rc ?? 0) !== 0) {
    fwrite(STDERR, "Warning: ProFTPD restart returned code {$rc}.\n");
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
function buildTlsConfiguration(string $hostname): string
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
            return implode("\n", [
                '    TLSEngine                     on',
                '    TLSLog                        /var/log/proftpd/tls.log',
                '    TLSProtocol                   TLSv1.2 TLSv1.3',
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
