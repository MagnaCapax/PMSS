<?php
/** Existing nginx TLS selection; setup.php retains certificate creation. */
/** Keep the host name inside one OpenSSL subject argument. */
function pmssNginxConfigSelfSignedCertCommand(string $hostname): ?string
{
    if (!pmssHostnameIsValid($hostname)) return null;

    return 'openssl req -x509 -nodes -days 365 -newkey rsa:2048 -subj '
        .escapeshellarg('/C=FI/ST=none/L=none/O=PulsedMedia/CN='.$hostname)
        .' -keyout /etc/nginx/ssl/nginx.key -out /etc/nginx/ssl/nginx.crt';
}

/** Require every certificate file; retain false/string template results. @return string|false */
function pmssNginxConfigSetupSsl(string $serverHostname)
{
    $fallback = @file_get_contents('/etc/seedbox/config/template.nginx-site-default-ssl');
    $certificateTemplate = @file_get_contents('/etc/seedbox/config/template.nginx-site-default-ssl-lets-encrypt');
    $certificatePath = '/etc/letsencrypt/live/'.$serverHostname;
    foreach ([
        $certificatePath.'/fullchain.pem', $certificatePath.'/privkey.pem',
        '/etc/letsencrypt/options-ssl-nginx.conf', '/etc/letsencrypt/ssl-dhparams.pem',
    ] as $path) {
        if (!file_exists($path) || !is_readable($path)) return $fallback;
    }
    return str_replace('||SERVER_HOSTNAME||', $serverHostname, $certificateTemplate);
}
