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
require_once __DIR__.'/../lib/certbotSetup.php';

function pmssSetupLetsEncryptMain(array $argv): int
{
    // Basic input validation: the automation expects an e-mail for certificate
    // registration so Let's Encrypt can deliver expiry notices.
    if (empty($argv[1])) {
        echo 'You need to pass e-mail address to this script';
        return 1;
    }

    $email = trim((string) $argv[1]);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\0\s]/', $email) === 1) {
        echo 'You need valid e-mail address';
        return 1;
    }

    // Gather the fqdn and Debian codename; the latter determines whether we
    // need the virtualenv install path still required on Debian 10 (buster).
    $domain = strtolower(trim(pmssHostnameRead()));
    if ($domain === '' || strpos($domain, '.') === false || !pmssHostnameIsValid($domain, false)) {
        echo "Unable to determine valid hostname for Let's Encrypt";
        return 1;
    }

    $codename = pmssDetectDistro()['codename'] ?: 'bullseye';

    try {
        pmssSetupLetsEncryptRun($domain, $email, $codename);
    } catch (RuntimeException $exception) {
        echo $exception->getMessage();
        return 1;
    }

    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssSetupLetsEncryptMain');
