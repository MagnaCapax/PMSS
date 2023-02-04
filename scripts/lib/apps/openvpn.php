<?php
return;		// currently no openvpn
// OpenVPN install!
// Setup OpenVPN

// Some basic configs first
$openvpnClientConfigHostname = $serverHostname;
if (strpos($openvpnClientConfigHostname, '.pulsedmedia.com') === false) $openvpnClientConfigHostname .= '.pulsedmedia.com';
$openvpnClientConfigFilename = str_replace('.', '-', $openvpnClientConfigHostname);
$openvpnCertInfo = array(
	'KEY_COUNTRY' => 'FI',
	'KEY_PROVINCE' => 'Uusimaa',
	'KEY_CITY' => 'Helsinki',
	'KEY_ORG' => 'Pulsed Media',
	'KEY_EMAIL' => 'sales@pulsedmedia'
);


if (!file_exists('/etc/openvpn/easy-rsa/2.0/')) {
    echo "#### Configuring OpenVPN\n";
    if ($debianVersion[0] == 8) {
        // Get easy-rsa
        `cd /etc/openvpn; wget http://pulsedmedia.com/remote/pkg/easy-rsa-2.0.tar.gz; tar -xzf easy-rsa-2.0.tar.gz; cd -`;
        // Fix file naming
        `ln -s /usr/lib/openvpn/openvpn-plugin-auth-pam.so /usr/lib/openvpn/openvpn-auth-pam.so`;


    } else {
        `cp -r /usr/share/doc/openvpn/examples/easy-rsa /etc/openvpn/`;
    }
   
    // Export key variables 
    foreach($openvpnCertInfo AS $openvpnCertKey => $openvpnCertValue)
		`export {$openvpnCertKey}="{$openvpnCertValue}"`;

    `cd /etc/openvpn/easy-rsa/2.0/; . ./vars; ./clean-all; ./build-ca --batch; ./build-key-server --batch server; ./build-dh --batch; cd -`;
    `cp -p /etc/seedbox/config/template.openvpn.server.config /etc/openvpn/openvpn.conf; /etc/init.d/openvpn restart`;
    echo "\t#### OpenVPN Configured. Create client config + cert package\n";

	// This is done below and at this stage those config files do not exist.
    //`cd /home; tar -czvf /etc/skel/www/openvpn-config.tgz openvpn-{$openvpnClientConfigFilename}.ovpn openvpn-{$openvpnClientConfigFilename}.crt; cd -`;

    foreach($users AS $thisUser)
        updateUserFile('www/openvpn-config.tgz', $thisUser);

}

// If template has changed update it
if ( file_get_contents('/etc/seedbox/config/template.openvpn.server.config') !== file_get_contents('/etc/openvpn/openvpn.conf') )
	`cp -p /etc/seedbox/config/template.openvpn.server.config /etc/openvpn/openvpn.conf; /etc/init.d/openvpn restart`;

/*
if ($debianVersion[0] == 8 && !file_exists('/etc/openvpn/easy-rsa')) {
    echo "#### Configuring OpenVPN\n";

    $easyrsaVars = file_get_contents('/etc/openvpn/easy-rsa/vars');
    $easyrsaVarsOriginal = <<<EOF
# These are the default values for fields
# which will be placed in the certificate.
# Don't leave any of these fields blank.
export KEY_COUNTRY="US"
export KEY_PROVINCE="CA"
export KEY_CITY="SanFrancisco"
export KEY_ORG="Fort-Funston"
export KEY_EMAIL="me@myhost.mydomain"
export KEY_OU="MyOrganizationalUnit"

# X509 Subject Field
export KEY_NAME="EasyRSA"
EOF;
    $easyrsaVarsReplace = <<<EOF
# These are the default values for fields
# which will be placed in the certificate.
# Don't leave any of these fields blank.
export KEY_COUNTRY="FI"
export KEY_PROVINCE="Uusimaa"
export KEY_CITY="Helsinki"
export KEY_ORG="Pulsed Media"
export KEY_EMAIL="sales@pulsedmedia.com"
export KEY_OU=""

# X509 Subject Field
export KEY_NAME="Pulsed Media {$serverHostname}"
EOF;

    $easyrsaVars = str_replace($easyrsaVarsOriginal, $easyrsaVarsReplace, $easyrsaVars);
    file_put_contents('/etc/openvpn/easy-rsa/vars', $easyrsaVars);

    `cd /etc/openvpn/easy-rsa/; . ./vars; ./clean-all; ./build-ca --batch; ./build-key-server --batch server; ./build-dh --batch; cd -`;
    `cp -p /etc/seedbox/config/template.openvpn.server.config /etc/openvpn/openvpn.conf; /etc/init.d/openvpn restart`;
    echo "\t#### OpenVPN Configured. Create client config + cert package\n";

}
*/

if (!file_exists("/home/openvpn-{$openvpnClientConfigFilename}.ovpn")) {
    $openvpnClientConfig = file_get_contents('/etc/seedbox/config/template.openvpn.client.config');

    $openvpnClientConfig = str_replace(
        array('##SERVER_HOSTNAME##', '##CONFIG_FILENAME##'),
        array($openvpnClientConfigHostname, 'openvpn-' . $openvpnClientConfigFilename),
        $openvpnClientConfig
    );
    file_put_contents("/home/openvpn-{$openvpnClientConfigFilename}.ovpn", $openvpnClientConfig);
}
if (!file_exists("/home/openvpn-{$openvpnClientConfigFilename}.crt")) {
    `cp -p /etc/openvpn/easy-rsa/2.0/keys/ca.crt /home/openvpn-{$openvpnClientConfigFilename}.crt`;
}
// OpenVPN Config check
if (!file_exists('/etc/skel/www/openvpn-config.tgz')) {

    if (file_exists("/home/openvpn-{$openvpnClientConfigFilename}.crt") &&
        file_exists("/home/openvpn-{$openvpnClientConfigFilename}.ovpn") ) {

        `cd /home; tar -czvf /etc/skel/www/openvpn-config.tgz openvpn-{$openvpnClientConfigFilename}.ovpn openvpn-{$openvpnClientConfigFilename}.crt; cd -`;

        foreach($users AS $thisUser)
            updateUserFile('www/openvpn-config.tgz', $thisUser);

    }

}

