<?php
// OpenVPN installation script

// Host naming //
// .pulsedmedia.com gets appended if it's missing
// Client config filenames use dashes instead of dots
$openvpnClientConfigHostname = $serverHostname;
// if (strpos($openvpnClientConfigHostname, '.pulsedmedia.com') === false) $openvpnClientConfigHostname .= '.pulsedmedia.com';
$openvpnClientConfigFilename = str_replace('.', '-', $openvpnClientConfigHostname);

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
        `cd /etc/openvpn; wget https://github.com/OpenVPN/easy-rsa/releases/download/v3.1.1/EasyRSA-3.1.1.tgz; tar -xzf EasyRSA-3.1.1.tgz; mv EasyRSA-3.1.1 easy-rsa; cd -`;
    } else {
        `cp -r /usr/share/easy-rsa /etc/openvpn/`;
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
    file_put_contents('/etc/openvpn/easy-rsa/vars', $easyrsaVars);

    // Create the server config and restart OpenVPN //
    `cd /etc/openvpn/easy-rsa/; ./easyrsa clean-all; ./easyrsa build-ca nopass; ./easyrsa build-server-full server nopass; ./easyrsa gen-dh; cd -`;
    `cp -p /etc/seedbox/config/template.openvpn.server.config /etc/openvpn/openvpn.conf; systemctl restart openvpn@openvpn`;
    echo "\t#### OpenVPN Configured. Create client config + cert package\n";
}

// Restart on template change //
if ( file_get_contents('/etc/seedbox/config/template.openvpn.server.config') !== file_get_contents('/etc/openvpn/openvpn.conf') )
	`cp -p /etc/seedbox/config/template.openvpn.server.config /etc/openvpn/openvpn.conf; systemctl restart openvpn@openvpn`;

// Create OpenVPN client config for this machine //
if (!file_exists("/home/openvpn-{$openvpnClientConfigFilename}.ovpn")) {
    $openvpnClientConfig = file_get_contents('/etc/seedbox/config/template.openvpn.client.config');
    $openvpnClientConfig = str_replace(
        array('##SERVER_HOSTNAME##', '##CONFIG_FILENAME##'),
        array($openvpnClientConfigHostname, 'openvpn-' . $openvpnClientConfigFilename),
        $openvpnClientConfig
    );
    file_put_contents("/home/openvpn-{$openvpnClientConfigFilename}.ovpn", $openvpnClientConfig);
}

// Copy out CA certificate if it isn't in /home yet //
if (!file_exists("/home/openvpn-{$openvpnClientConfigFilename}.crt")) {
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

