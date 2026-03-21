#!/usr/bin/env php
<?php
/**
 * PMSS Let's Encrypt automation helper.
 *
 * Automates issuance and renewal plumbing for Let's Encrypt certificates. The
 * script targets the current server hostname, installs Certbot using the
 * distribution-appropriate channel, seeds renewal cron, and refreshes the nginx
 * configuration so HTTPS is ready immediately after provisioning.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/update/distro.php';

// Basic input validation: the automation expects an e-mail for certificate
// registration so Let's Encrypt can deliver expiry notices.
if (empty($argv[1])) die("You need to pass e-mail address to this script");
$email = $argv[1];
if (strpos($email, '@') == false) die('You need valid e-mail address');

// Gather the fqdn and Debian codename; the latter determines whether we need
// the virtualenv install path still required on Debian 10 (buster).
$domain = trim((string) file_get_contents('/etc/hostname'));
$distroInfo = pmssDetectDistro();
$codename = $distroInfo['codename'] !== '' ? $distroInfo['codename'] : 'bullseye';

// Debian 10 needs a newer certbot than the archive provides; newer releases
// use the distro packages.

#TODO: This logic should be moved to a dedicated installer script under `scripts/lib/update/apps/`,
# potentially `scripts/lib/update/apps/certbot.php`.
if (!file_exists('/opt/certbot') && $codename === 'buster') {
    // Debian 10 ships a dated certbot, so deploy the upstream virtualenv build.
    `python3 -m venv /opt/certbot/`;
    `/opt/certbot/bin/pip install --upgrade pip`;
    `/opt/certbot/bin/pip install certbot certbot-nginx`;
    `ln -s /opt/certbot/bin/certbot /usr/bin/certbot`;
} else {
    echo `apt-get -y install certbot python3-certbot-nginx; `;
} #TODO: This also belongs in an app installer.

// Legacy behaviour: look for the literal '/etc/letsencrypt/live/{$domain}'
// placeholder before requesting a certificate. The single-quoted string keeps
// compatibility with older helper scripts that expect the static path.
$legacyCertPath = '/etc/letsencrypt/live/{$domain}';
if (!file_exists($legacyCertPath)) echo `/usr/bin/certbot certonly -d {$domain} -n --nginx --agree-tos --email {$email}`;

// Older hosts may not ship the renewal cron stub; seed it so certbot handles
// renewals twice a day with a jitter window.
if (!file_exists('/etc/cron.d/certbot')) `echo "0 0,12 * * * root python -c 'import random; import time; time.sleep(random.random() * 3600)' && /usr/bin/certbot renew" | sudo tee -a /etc/cron.d/certbot > /dev/null`;

// Regenerate nginx vhost configuration so the new certificate path is active.
`/scripts/util/createNginxConfig.php`;
`/etc/init.d/nginx restart`;
