<?php
// OpenVPN installation script
// #TODO(complexity-refactor): Replace ad-hoc exec/backticks with runStep
// wrappers; move package management to dpkg baselines/repo installs. Split
// installer into idempotent steps (install → configure → restart) with clear
// decision points to reduce branching and improve readability.

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
if (strpos($openvpnClientConfigHostname, '.pulsedmedia.com') === false) $openvpnClientConfigHostname .= '.pulsedmedia.com';
$openvpnClientConfigFilename = str_replace('.', '-', $openvpnClientConfigHostname);

@mkdir('/etc/openvpn', 0755, true);

// Ensure OpenVPN/EasyRSA bits exist (install if missing) and avoid noisy errors.
$easyRsaDir      = '/etc/openvpn/easy-rsa';
$easyRsaShareDir = '/usr/share/easy-rsa';
$easyRsaScript   = $easyRsaDir.'/easyrsa';

if (!is_dir($easyRsaDir) && !file_exists($easyRsaScript)) {
    // Try to provision from packaged easy-rsa first; fall back to GitHub tarball.
    // Keep quiet on failure; downstream steps check existence before use.
    @mkdir($easyRsaDir, 0755, true);
    if (!is_dir($easyRsaShareDir)) {
        // Attempt to install dependencies if package not present
        @passthru('apt-get update -yq || true');
        @passthru('apt-get install -yq openvpn easy-rsa || true');
    }
    if (is_dir($easyRsaShareDir)) {
        @passthru('cp -r /usr/share/easy-rsa /etc/openvpn/ 2>/dev/null');
    } else {
        // Fallback: fetch a known EasyRSA v3 release
        @passthru('cd /etc/openvpn; wget -q https://github.com/OpenVPN/easy-rsa/releases/download/v3.1.1/EasyRSA-3.1.1.tgz -O EasyRSA.tgz && tar -xzf EasyRSA.tgz && mv EasyRSA-3.1.1 easy-rsa || true');
    }
}

// Detect old config //
if (file_exists("/etc/openvpn/easy-rsa/2.0")) {
    echo "#### Found old EasyRSA config, moving it away";
    `mv /etc/openvpn/easy-rsa /etc/openvpn/easy-rsa-old`;
}

// Configure EasyRSA and OpenVPN //
if (!file_exists('/etc/openvpn/easy-rsa')) {
    echo "#### Configuring OpenVPN\n";

    // EasyRSA installation //
    if ($debianVersion[0] == 8) {
        // If running on Debian 8, fetch an up-to-date copy of EasyRSA
        // #TODO Switch to HTTPS and checksum validation.
        `cd /etc/openvpn; wget https://github.com/OpenVPN/easy-rsa/releases/download/v3.1.1/EasyRSA-3.1.1.tgz; tar -xzf EasyRSA-3.1.1.tgz; mv EasyRSA-3.1.1 easy-rsa; cd -`;
    } else {
        if (is_dir('/usr/share/easy-rsa')) {
            `cp -r /usr/share/easy-rsa /etc/openvpn/`;
        }
    }

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
    @mkdir('/etc/openvpn/easy-rsa', 0755, true);
    @file_put_contents('/etc/openvpn/easy-rsa/vars', $easyrsaVars);

    // Create the server config and restart OpenVPN //
    if (file_exists('/etc/openvpn/easy-rsa/easyrsa')) {
        // EasyRSA v3 command set
        `cd /etc/openvpn/easy-rsa/; ./easyrsa init-pki; ./easyrsa build-ca nopass; ./easyrsa build-server-full server nopass; ./easyrsa gen-dh; cd -`;
    }
    if (file_exists('/etc/seedbox/config/template.openvpn.server.config')) {
        @mkdir('/etc/openvpn', 0755, true);
        `cp -p /etc/seedbox/config/template.openvpn.server.config /etc/openvpn/openvpn.conf`;
    }

    if (is_dir('/run/systemd/system')) {
        // Avoid noisy "Unit not found" messages
        @passthru('systemctl restart openvpn@openvpn 2>/dev/null || systemctl restart openvpn 2>/dev/null || true');
    } elseif (file_exists('/etc/init.d/openvpn')) {
        @passthru('/etc/init.d/openvpn restart');
    }
    echo "\t#### OpenVPN Configured. Create client config + cert package\n";
}

// Restart on template change //
if (file_exists('/etc/openvpn/openvpn.conf') && file_exists('/etc/seedbox/config/template.openvpn.server.config')
    && file_get_contents('/etc/seedbox/config/template.openvpn.server.config') !== file_get_contents('/etc/openvpn/openvpn.conf')) {
    `cp -p /etc/seedbox/config/template.openvpn.server.config /etc/openvpn/openvpn.conf`;
    if (is_dir('/run/systemd/system')) {
        passthru('systemctl restart openvpn@openvpn || systemctl restart openvpn || true');
    } elseif (file_exists('/etc/init.d/openvpn')) {
        passthru('/etc/init.d/openvpn restart');
    }
}

// Create OpenVPN client config for this machine //
if (!file_exists("/home/openvpn-{$openvpnClientConfigFilename}.ovpn") && file_exists('/etc/seedbox/config/template.openvpn.client.config')) {
    $openvpnClientConfig = file_get_contents('/etc/seedbox/config/template.openvpn.client.config');
    $openvpnClientConfig = str_replace(
        array('##SERVER_HOSTNAME##', '##CONFIG_FILENAME##'),
        array($openvpnClientConfigHostname, 'openvpn-' . $openvpnClientConfigFilename),
        $openvpnClientConfig
    );
    @file_put_contents("/home/openvpn-{$openvpnClientConfigFilename}.ovpn", $openvpnClientConfig);
}

// Copy out CA certificate if it isn't in /home yet //
if (!file_exists("/home/openvpn-{$openvpnClientConfigFilename}.crt") && file_exists('/etc/openvpn/easy-rsa/pki/ca.crt')) {
    `cp -p /etc/openvpn/easy-rsa/pki/ca.crt /home/openvpn-{$openvpnClientConfigFilename}.crt`;
}

// Add openvpn-config.tgz to skel and put it in homedirs //
// This runs only if there is no config package yet and the certificate and profile are present.
if (!file_exists('/etc/skel/www/openvpn-config.tgz') &&
    file_exists("/home/openvpn-{$openvpnClientConfigFilename}.crt") &&
    file_exists("/home/openvpn-{$openvpnClientConfigFilename}.ovpn")) {
    `cd /home; tar -czvf /etc/skel/www/openvpn-config.tgz openvpn-{$openvpnClientConfigFilename}.ovpn openvpn-{$openvpnClientConfigFilename}.crt; cd -`;
        foreach($users AS $thisUser)
            updateUserFile('www/openvpn-config.tgz', $thisUser);
}
