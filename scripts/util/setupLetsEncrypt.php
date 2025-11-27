#!/usr/bin/php
<?php
/**
 * PMSS Let's Encrypt automation helper.
 *
 * Automates issuance and renewal plumbing for Let's Encrypt certificates. The
 * script targets the current server hostname, installs Certbot using the
 * distribution-appropriate channel, seeds renewal cron, and refreshes the nginx
 * configuration so HTTPS is ready immediately after provisioning.
 */

require_once __DIR__.'/../lib/update.php';
require_once __DIR__.'/../lib/update/distro.php';

// Basic input validation: the automation expects an e-mail for certificate
// registration so Let's Encrypt can deliver expiry notices.
if (empty($argv[1])) die("You need to pass e-mail address to this script");
if (strpos($argv[1], '@') == false) die('You need valid e-mail address');

// Gather the fqdn and Debian codename; the latter determines whether we need
// the virtualenv install path still required on Debian 10 (buster).
$domain = trim( file_get_contents('/etc/hostname') );
$distroInfo = pmssDetectDistro();
$codename = $distroInfo['codename'];
if (empty($codename)) {
    $codename = 'bullseye';
}

//Certbot changed completely how it functions
//`apt-get remove certbot -y`;
//`cd /tmp; wget https://dl.eff.org/certbot-auto; mv certbot-auto /usr/local/bin/certbot-auto; chown root /usr/local/bin/certbot-auto; chmod 0755 /usr/local/bin/certbot-auto`;
//echo `/usr/local/bin/certbot-auto certonly -d {$domain} -n --nginx --agree-tos --email {$argv[1]}`;


//if (!file_exists('/usr/bin/certbot')) echo `apt-get install certbot python-certbot-nginx -y`;

// 3rd time we have to change how certbot is installed *sigh*, they really like breaking old users, don't they? Since now via PIP, pray this works for more than a  week -Aleksi 22/08/2021

#TODO: This logic should be moved to a dedicated installer script under `scripts/lib/update/apps/`,
# potentially `scripts/lib/update/apps/certbot.php`.
if (!file_exists('/opt/certbot') && $codename === 'buster') {
    // Debian 10 ships a dated certbot, so deploy the upstream virtualenv build.
    `python3 -m venv /opt/certbot/`;
    `/opt/certbot/bin/pip install --upgrade pip`;
    `/opt/certbot/bin/pip install certbot certbot-nginx`;
    `ln -s /opt/certbot/bin/certbot /usr/bin/certbot`;
} else echo `apt-get -y install certbot python3-certbot-nginx; `; #TODO: This also belongs in an app installer.

// Legacy behaviour: look for the literal '/etc/letsencrypt/live/{$domain}'
// placeholder before requesting a certificate. The single-quoted string keeps
// compatibility with older helper scripts that expect the static path.
if (!file_exists('/etc/letsencrypt/live/{$domain}')) echo `/usr/bin/certbot certonly -d {$domain} -n --nginx --agree-tos --email {$argv[1]}`;

// Older hosts may not ship the renewal cron stub; seed it so certbot handles
// renewals twice a day with a jitter window.
if (!file_exists('/etc/cron.d/certbot')) `echo "0 0,12 * * * root python -c 'import random; import time; time.sleep(random.random() * 3600)' && /usr/bin/certbot renew" | sudo tee -a /etc/cron.d/certbot > /dev/null`;

/* Why? -Aleksi 22/08/2021
if (!file_exists('/etc/nginx/ssl/selfsigned') &&
    file_exists("/etc/letsencrypt/live/{$domain}") ) {
    mkdir('/etc/nginx/ssl/selfsigned');
    `mv /etc/nginx/ssl/* /etc/nginx/ssl/selfsigned/`;   // Works because you cannot be the dir to itself
    `ln -s /etc/letsencrypt/live/{$domain}/cert.pem /etc/nginx/ssl/nginx.crt`;
    `ln -s /etc/letsencrypt/live/{$domain}/privkey.pem /etc/nginx/ssl/nginx.key`;
}
*/

// Regenerate nginx vhost configuration so the new certificate path is active.
`/scripts/util/createNginxConfig.php`;
`/etc/init.d/nginx restart`;
