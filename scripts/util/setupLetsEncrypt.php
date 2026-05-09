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

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/update/distro.php';
require_once __DIR__.'/../lib/userTransfer/cliParse.php';

// Basic input validation: the automation expects an e-mail for certificate
// registration so Let's Encrypt can deliver expiry notices.
if (empty($argv[1])) die("You need to pass e-mail address to this script");
$email = trim((string) $argv[1]);
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\0\s]/', $email) === 1) {
    die('You need valid e-mail address');
}

// Gather the fqdn and Debian codename; the latter determines whether we need
// the virtualenv install path still required on Debian 10 (buster).
$domain = strtolower(trim(pmssHostnameRead()));
if (
    $domain === ''
    || strpos($domain, '.') === false
    || filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
    || !pmssUserTransferHostnameIsValid($domain)
) {
    die("Unable to determine valid hostname for Let's Encrypt");
}
$domainArg = escapeshellarg($domain);
$emailArg = escapeshellarg($email);
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

// Only request a certificate when the live directory for this hostname is
// absent; otherwise every update run would invoke certbot needlessly.
$legacyCertPath = "/etc/letsencrypt/live/{$domain}";
if (!file_exists($legacyCertPath)) echo (string) shell_exec('/usr/bin/certbot certonly -d '.$domainArg.' -n --nginx --agree-tos --email '.$emailArg);

// Older hosts may not ship the renewal cron stub; seed it so certbot handles
// renewals twice a day with a jitter window.
if (!file_exists('/etc/cron.d/certbot')) `echo "0 0,12 * * * root python -c 'import random; import time; time.sleep(random.random() * 3600)' && /usr/bin/certbot renew" | sudo tee -a /etc/cron.d/certbot > /dev/null`;

// Regenerate nginx vhost configuration so the new certificate path is active.
`/scripts/util/createNginxConfig.php`;
`/etc/init.d/nginx restart`;
