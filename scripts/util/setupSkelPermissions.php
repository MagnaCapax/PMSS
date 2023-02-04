#!/usr/bin/php
<?php
passthru("cd /etc/skel; chmod o-w * -R; chmod o-w .* -R"); // not using 775 because there might be places where the perms differ and need to differ
passthru("chmod 770 /etc/skel");

// Setup openvpn config perms

passthru('chmod 771 /etc/openvpn');
`chmod 640 /etc/openvpn/openvpn.conf`;
`chmod 771 /etc/openvpn/easy-rsa`;
`chmod 664 /etc/seedbox/config/localnet`;
@`chmod 664 /etc/seedbox/localnet`;

