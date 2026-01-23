<?php
// OpenVPN installation script.

if (!function_exists('runStep')) {
    require_once __DIR__.'/../runtime/commands.php';
}

if (!function_exists('pmssOpenvpnLog')) {
    function pmssOpenvpnLog(string $message): void
    {
        if (function_exists('logmsg')) {
            logmsg($message);
            return;
        }
        if (function_exists('logMessage')) {
            logMessage($message);
            return;
        }
        echo $message.PHP_EOL;
    }
}

if (!function_exists('pmssOpenvpnRestartService')) {
    function pmssOpenvpnRestartService(): void
    {
        if (is_dir('/run/systemd/system')) {
            $rc = runStep('Restarting OpenVPN (openvpn@openvpn)', 'systemctl restart openvpn@openvpn');
            if ($rc !== 0) {
                runStep('Restarting OpenVPN (openvpn)', 'systemctl restart openvpn');
            }
            return;
        }
        if (file_exists('/etc/init.d/openvpn')) {
            runStep('Restarting OpenVPN (init.d)', '/etc/init.d/openvpn restart');
        }
    }
}

if (!function_exists('pmssOpenvpnFetchEasyRsaTarball')) {
    function pmssOpenvpnFetchEasyRsaTarball(string $url, string $expectedSha256, string $tarPath, string $extractDir, string $targetDir): bool
    {
        $fetchCmd = 'wget -q -O '.escapeshellarg($tarPath).' '.escapeshellarg($url);
        if (runStep('Downloading EasyRSA tarball', $fetchCmd) !== 0) {
            return false;
        }
        $actual = @hash_file('sha256', $tarPath);
        if ($actual === false || strtolower($actual) !== strtolower($expectedSha256)) {
            pmssOpenvpnLog('[WARN] EasyRSA tarball checksum mismatch; download discarded');
            @unlink($tarPath);
            return false;
        }
        runStep('Extracting EasyRSA tarball', 'tar -xzf '.escapeshellarg($tarPath).' -C /etc/openvpn');
        if (is_dir($extractDir) && !is_dir($targetDir)) {
            runStep('Installing EasyRSA', sprintf('mv %s %s', escapeshellarg($extractDir), escapeshellarg($targetDir)));
        }
        return is_dir($targetDir);
    }
}

// Host naming //
// .pulsedmedia.com gets appended if it's missing
// Client config filenames use dashes instead of dots
// Ensure serverHostname is defined on older hosts where the caller didn't set it.
$serverHostname = isset($serverHostname) && $serverHostname !== ''
    ? (string)$serverHostname
    : trim((string) @file_get_contents('/etc/hostname'));
$debianVersion = isset($debianVersion) ? $debianVersion : array('0');
if (is_string($debianVersion)) {
    $debianVersion = explode('.', $debianVersion);
}
if (!is_array($debianVersion) || !isset($debianVersion[0])) {
    $debianVersion = array('0');
}
$users = isset($users) ? $users : array();
if (is_string($users)) {
    $users = preg_split('/\\r?\\n/', trim($users));
    $users = $users === false ? array() : $users;
}
if (!is_array($users)) {
    $users = array();
}
$openvpnClientConfigHostname = $serverHostname;
if (strpos($openvpnClientConfigHostname, '.pulsedmedia.com') === false) {
    $openvpnClientConfigHostname .= '.pulsedmedia.com';
}
$openvpnClientConfigFilename = str_replace('.', '-', $openvpnClientConfigHostname);

@mkdir('/etc/openvpn', 0755, true);

// Ensure OpenVPN/EasyRSA bits exist (install if missing) and avoid noisy errors.
$easyRsaDir        = '/etc/openvpn/easy-rsa';
$easyRsaShareDir   = '/usr/share/easy-rsa';
$easyRsaScript     = $easyRsaDir.'/easyrsa';
$easyRsaTarUrl     = 'https://github.com/OpenVPN/easy-rsa/releases/download/v3.1.1/EasyRSA-3.1.1.tgz';
$easyRsaTarSha256  = '779d425cacf1de56262b7a7ed6b90b36e614ce9273f08ad7b86992740cb3b2a5';
$easyRsaTarPath    = '/etc/openvpn/EasyRSA-3.1.1.tgz';
$easyRsaExtractDir = '/etc/openvpn/EasyRSA-3.1.1';

if (!is_dir($easyRsaDir) && !file_exists($easyRsaScript)) {
    // Try to provision from packaged easy-rsa first; fall back to GitHub tarball.
    // Keep quiet on failure; downstream steps check existence before use.
    @mkdir($easyRsaDir, 0755, true);
    if (!is_dir($easyRsaShareDir)) {
        runStep('Preparing APT metadata for EasyRSA', 'apt-get update -yq');
        runStep('Installing OpenVPN/EasyRSA packages', 'apt-get install -yq openvpn easy-rsa');
    }
    clearstatcache();
    if (is_dir($easyRsaShareDir)) {
        runStep('Copying EasyRSA from package', 'cp -r /usr/share/easy-rsa /etc/openvpn/');
    } else {
        pmssOpenvpnFetchEasyRsaTarball($easyRsaTarUrl, $easyRsaTarSha256, $easyRsaTarPath, $easyRsaExtractDir, $easyRsaDir);
    }
}

// Detect old config //
if (file_exists($easyRsaDir.'/2.0')) {
    echo "#### Found old EasyRSA config, moving it away";
    runStep('Archiving legacy EasyRSA directory', sprintf('mv %s %s', escapeshellarg($easyRsaDir), escapeshellarg($easyRsaDir.'-old')));
}

$easyRsaReady = is_dir($easyRsaDir) && is_file($easyRsaScript);
$needsRestart = false;

// EasyRSA variables //
// Note that vars should not be sourced anymore, it's read automatically
// Additionally, EASYRSA_BATCH is used to prevent prompting for user input
$easyrsaVars = <<<EOF
set_var EASYRSA_REQ_COUNTRY "FI"
set_var EASYRSA_REQ_PROVINCE "Uusimaa"
set_var EASYRSA_REQ_CITY "Helsinki"
set_var EASYRSA_REQ_ORG "Pulsed Media"
set_var EASYRSA_REQ_EMAIL "sales@pulsedmedia.com"
set_var EASYRSA_BATCH "1"
EOF;
if ($easyRsaReady) {
    $varsPath = $easyRsaDir.'/vars';
    $varsCurrent = @file_get_contents($varsPath);
    if ($varsCurrent === false || $varsCurrent !== $easyrsaVars) {
        @mkdir($easyRsaDir, 0755, true);
        @file_put_contents($varsPath, $easyrsaVars);
    }
}

// Create PKI and server certs when missing.
if ($easyRsaReady && !file_exists($easyRsaDir.'/pki/ca.crt')) {
    echo "#### Configuring OpenVPN\n";
    $cmdPrefix = 'cd '.escapeshellarg($easyRsaDir).' && ';
    runStep('EasyRSA init-pki', $cmdPrefix.'./easyrsa init-pki');
    runStep('EasyRSA build-ca', $cmdPrefix.'./easyrsa build-ca nopass');
    runStep('EasyRSA build-server-full', $cmdPrefix.'./easyrsa build-server-full server nopass');
    runStep('EasyRSA gen-dh', $cmdPrefix.'./easyrsa gen-dh');
    $needsRestart = true;
}

$serverTemplate = '/etc/seedbox/config/template.openvpn.server.config';
$serverConfig   = '/etc/openvpn/openvpn.conf';
if (file_exists($serverTemplate)) {
    $templateContent = @file_get_contents($serverTemplate);
    if ($templateContent !== false) {
        $currentContent = @file_get_contents($serverConfig);
        if ($currentContent === false || $currentContent !== $templateContent) {
            runStep('Updating OpenVPN server config', sprintf('cp -p %s %s', escapeshellarg($serverTemplate), escapeshellarg($serverConfig)));
            $needsRestart = true;
        }
    }
}

if ($needsRestart) {
    pmssOpenvpnRestartService();
    echo "\t#### OpenVPN Configured. Create client config + cert package\n";
}

// Create OpenVPN client config for this machine //
$clientConfigPath = "/home/openvpn-{$openvpnClientConfigFilename}.ovpn";
if (!file_exists($clientConfigPath) && file_exists('/etc/seedbox/config/template.openvpn.client.config')) {
    $openvpnClientConfig = file_get_contents('/etc/seedbox/config/template.openvpn.client.config');
    $openvpnClientConfig = str_replace(
        array('##SERVER_HOSTNAME##', '##CONFIG_FILENAME##'),
        array($openvpnClientConfigHostname, 'openvpn-' . $openvpnClientConfigFilename),
        $openvpnClientConfig
    );
    @file_put_contents($clientConfigPath, $openvpnClientConfig);
}

// Copy out CA certificate if it isn't in /home yet //
$clientCertPath = "/home/openvpn-{$openvpnClientConfigFilename}.crt";
if (!file_exists($clientCertPath) && file_exists('/etc/openvpn/easy-rsa/pki/ca.crt')) {
    runStep('Copying OpenVPN CA certificate', sprintf('cp -p %s %s', escapeshellarg('/etc/openvpn/easy-rsa/pki/ca.crt'), escapeshellarg($clientCertPath)));
}

// Add openvpn-config.tgz to skel and put it in homedirs //
// This runs only if there is no config package yet and the certificate and profile are present.
if (!file_exists('/etc/skel/www/openvpn-config.tgz') &&
    file_exists($clientCertPath) &&
    file_exists($clientConfigPath)) {
    $tarCmd = sprintf(
        'tar -czvf %s -C /home %s %s',
        escapeshellarg('/etc/skel/www/openvpn-config.tgz'),
        escapeshellarg(basename($clientConfigPath)),
        escapeshellarg(basename($clientCertPath))
    );
    runStep('Creating OpenVPN client bundle', $tarCmd);
    foreach ($users as $thisUser) {
        updateUserFile('www/openvpn-config.tgz', $thisUser);
    }
}
