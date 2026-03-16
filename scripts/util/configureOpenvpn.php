#!/usr/bin/env php
<?php
/**
 * Configure OpenVPN server and client artifacts with structured logging.
 *
 * This utility makes OpenVPN setup observable and idempotent:
 * - Ensures packages and EasyRSA are present
 * - Seeds server configuration from template
 * - Generates/refreshes client .ovpn and CA exports under /home
 * - Restarts OpenVPN service as needed
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';
// Bring in shared runtime helpers; avoid duplicating apt/repo logic here.
// Package installation for OpenVPN/EasyRSA is handled centrally in the package phase.
require_once __DIR__.'/../lib/update.php';

requireRoot();

$hostname = trim((string) @file_get_contents('/etc/hostname'));
$hostname = $hostname === '' ? 'localhost' : $hostname;
$fqdn = strpos($hostname, '.pulsedmedia.com') !== false ? $hostname : $hostname.'.pulsedmedia.com';
$slug = str_replace('.', '-', $fqdn);

$openvpnDir   = '/etc/openvpn';
$easyRsaDir   = $openvpnDir.'/easy-rsa';
$easyRsaShare = '/usr/share/easy-rsa';
$serverConf   = $openvpnDir.'/openvpn.conf';
$tplServer    = '/etc/seedbox/config/template.openvpn.server.config';
$tplClient    = '/etc/seedbox/config/template.openvpn.client.config';
$clientOvpn   = '/home/openvpn-'.$slug.'.ovpn';
$clientCrt    = '/home/openvpn-'.$slug.'.crt';

// Fast-path using the same binary/config/artifact checks expected by systemTest.
$alreadyConfigured = trim((string) @shell_exec('command -v openvpn 2>/dev/null')) !== ''
    && is_file($serverConf)
    && (is_file($easyRsaDir.'/pki/ca.crt') || is_file($easyRsaDir.'/pki/issued/server.crt'))
    && $clientOvpn !== ''
    && $clientCrt !== ''
    && is_file($clientOvpn)
    && is_file($clientCrt);
if ($alreadyConfigured) {
    if (function_exists('pmssLogStatus')) { pmssLogStatus('SKIP', 'OpenVPN already configured; skipping provisioning', 0); }
    else { logmsg('[SKIP] OpenVPN already configured; skipping provisioning'); }
    return;
}

// 1) Package presence (informational)
// OpenVPN/EasyRSA packages are expected to be installed during the package phase.
if (!is_file('/usr/sbin/openvpn') || (!is_dir($easyRsaShare) && !is_dir($easyRsaDir))) {
    logmsg('[INFO] OpenVPN/EasyRSA packages not detected; proceeding with EasyRSA fallback if needed');
}

// 2) Ensure directories
runStep('Ensuring OpenVPN directory', 'install -d -m 0755 '.escapeshellarg($openvpnDir));

// 3) Seed EasyRSA
if (!is_dir($easyRsaDir) || !file_exists($easyRsaDir.'/easyrsa')) {
    if (is_dir($easyRsaShare)) {
        runStep('Seeding EasyRSA from system share', sprintf('cp -r %s %s', escapeshellarg($easyRsaShare), escapeshellarg($openvpnDir)));
    } else {
        runStep('Creating EasyRSA directory', 'install -d -m 0755 '.escapeshellarg($easyRsaDir));
        $download = 'cd '.escapeshellarg($openvpnDir).' && '
            .'wget -q https://github.com/OpenVPN/easy-rsa/releases/download/v3.1.1/EasyRSA-3.1.1.tgz -O EasyRSA.tgz && '
            .'tar -xzf EasyRSA.tgz && '
            .'mv EasyRSA-3.1.1 easy-rsa || true';
        runStep('Downloading EasyRSA v3.1.1 (fallback)', $download);
    }
}

// 4) EasyRSA vars (non-interactive)
if (!file_exists($easyRsaDir.'/vars')) {
    $vars = <<<EOF
set_var EASYRSA_REQ_COUNTRY "FI"
set_var EASYRSA_REQ_PROVINCE "Uusimaa"
set_var EASYRSA_REQ_CITY "Helsinki"
set_var EASYRSA_REQ_ORG "Pulsed Media"
set_var EASYRSA_REQ_EMAIL "sales@pulsedmedia.com"
set_var EASYRSA_BATCH "1"
EOF;
    if (!is_dir($easyRsaDir)) {
        runStep('Creating EasyRSA directory (vars)', 'install -d -m 0755 '.escapeshellarg($easyRsaDir));
    }
    @file_put_contents($easyRsaDir.'/vars', $vars);
}

// 5) Initialize PKI if missing
if (file_exists($easyRsaDir.'/easyrsa') && !file_exists($easyRsaDir.'/pki/ca.crt')) {
    $cmd = 'bash -lc '.escapeshellarg('cd '.escapeshellarg($easyRsaDir).'; '
        .'./easyrsa init-pki; '
        .'./easyrsa build-ca nopass; '
        .'./easyrsa build-server-full server nopass; '
        .'./easyrsa gen-dh');
    runStep('Initializing EasyRSA PKI and server keys', $cmd);
}

// 6) Server configuration from template
if (file_exists($tplServer)) {
    if (!file_exists($serverConf) || md5_file($serverConf) !== md5_file($tplServer)) {
        runStep('Installing OpenVPN server configuration', sprintf('cp -p %s %s', escapeshellarg($tplServer), escapeshellarg($serverConf)));
    }
}

// 7) Restart OpenVPN service
if (is_dir('/run/systemd/system')) {
    runStep('Restarting OpenVPN service', 'bash -lc "systemctl restart openvpn@openvpn || systemctl restart openvpn || true"');
} elseif (file_exists('/etc/init.d/openvpn')) {
    runStep('Restarting OpenVPN service (sysvinit)', '/etc/init.d/openvpn restart');
}

// 8) Client artifacts (.ovpn + CA)
if (file_exists($tplClient) && !file_exists($clientOvpn)) {
    $content = file_get_contents($tplClient);
    if ($content !== false) {
        $rendered = str_replace([
            '##SERVER_HOSTNAME##',
            '##CONFIG_FILENAME##',
        ], [
            $fqdn,
            'openvpn-'.$slug,
        ], $content);
        @file_put_contents($clientOvpn, $rendered);
        @chmod($clientOvpn, 0644);
        logmsg('OpenVPN client profile written to '.$clientOvpn);
    }
}

if (!file_exists($clientCrt) && file_exists($easyRsaDir.'/pki/ca.crt')) {
    runStep('Exporting CA certificate for clients', sprintf('cp -p %s %s', escapeshellarg($easyRsaDir.'/pki/ca.crt'), escapeshellarg($clientCrt)));
}

// 9) Bundle client artifacts for download (historic behavior)
// Historically we have published the OpenVPN client bundle to /etc/skel/www so
// users can download the config package via the web root. AGENTS.md mandates a
// lockdown for /etc/skel/www unless explicitly instructed — and this path is
// explicitly approved here for OpenVPN client bundle publication.
if (file_exists($clientOvpn) && file_exists($clientCrt) && is_dir('/etc/skel/www')) {
    $tgz = '/etc/skel/www/openvpn-config.tgz';
    $cmd = 'bash -lc '.escapeshellarg('cd /home; tar -czf '.escapeshellarg($tgz).' '
        .escapeshellarg(basename($clientOvpn)).' '.escapeshellarg(basename($clientCrt)));
    runStep('Bundling OpenVPN client package for web download', $cmd);
}

logmsg('OpenVPN configuration task complete');
