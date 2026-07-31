<?php
/**
 * Nginx per-user subdomain helpers.
 *
 * These helpers keep hostname validation and hash host derivation consistent
 * across nginx config generation without altering existing path-based routes.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
require_once __DIR__.'/user/billingIds.php';

/**
 * Validate that the hostname is a usable FQDN (no IPs, must contain a dot).
 */
function pmssNginxUserHostIsValidFqdn(string $hostname): bool
{
    $trimmed = strtolower(trim($hostname));
    return $trimmed !== '' && strpos($trimmed, '.') !== false && pmssHostnameIsValid($trimmed, false);
}

/**
 * Read and validate the billing service ID stored in user homes.
 */
function pmssNginxUserBillingServiceIdFromHome(string $home): ?string
{
    return pmssUserBillingServiceIdDigitsRead($home);
}

/**
 * Build the SHA256 host prefix for a user.
 */
function pmssNginxUserHashHostname(string $username, string $billingServiceId, string $hostname): string
{
    return hash('sha256', $username.'.'.$billingServiceId.'.'.$hostname).'.'.$hostname;
}

/**
 * Stable mcx.fi service hostname for a user's billing service id.
 *
 * Mirrors the mcx.fi DNS builder VERBATIM (web4 remote/mcxData-api.php:
 * substr(sha256("mcx.fi:service:".serviceid),0,16)) so the name this server
 * serves by Host matches the A record the builder already publishes in the
 * mcx.fi zone. Public sha256-cut-16, no secret (operator-chosen, customer-computable).
 */
function pmssNginxUserMcxHostname(string $billingServiceId): string
{
    return substr(hash('sha256', 'mcx.fi:service:'.$billingServiceId), 0, 16).'.mcx.fi';
}

/**
 * Stable mcx.fi CLUSTER hostname for a user's billing client id.
 *
 * Mirrors the mcx.fi DNS builder VERBATIM (ns0-build-mcx.php:
 * substr(sha256("mcx.fi:customer:".clientid),0,16)) — the same relationship the
 * service hostname above has to its own record. The builder publishes this label
 * as multi-A round-robin across every node holding one of that customer's
 * services, so each node must answer for it to serve the customer's content.
 *
 * The client id is already on the node (.billingClientId, read via
 * pmssUserBillingClientIdRead) — no remote lookup is needed to derive this.
 *
 * The builder only emits a cluster record for customers with 2+ active
 * services. A single-service user therefore gets a server_name entry that never
 * resolves; nginx simply never receives a request for it, so no guard is needed.
 */
function pmssNginxUserMcxClusterHostname(string $billingClientId): string
{
    return substr(hash('sha256', 'mcx.fi:customer:'.$billingClientId), 0, 16).'.mcx.fi';
}

/**
 * Choose the SSL block for a user's public subdomain vhost.
 *
 * Per-name HTTPS is OPT-IN (see docs/adr/0039): a customer only gets a valid
 * certificate for their own public names after requesting it, because issuing a
 * cert per name for the whole fleet would be wasteful and strain the LE
 * per-registered-domain rate limit. Until then the vhost falls back to the host
 * certificate (name-mismatch warning) exactly as before — this function changes
 * nothing for a user who has not opted in.
 *
 * The certificate, when it exists, is the standard root-owned certbot output at
 * /etc/letsencrypt/live/<primaryHost>/ (issued for the user's public names as
 * SANs). nginx never reads a certificate out of a customer home.
 *
 * @param string $primaryHost      The user's own public FQDN (user.server.tld).
 * @param string $hostFallbackBlock The existing host-wide ssl block.
 * @param string $liveDir          Certbot live dir (overridable for tests).
 */
function pmssNginxUserSslBlock(string $primaryHost, string $hostFallbackBlock, string $liveDir = '/etc/letsencrypt/live'): string
{
    if (!pmssNginxUserHostIsValidFqdn($primaryHost)) {
        return $hostFallbackBlock;
    }
    $certDir = $liveDir.'/'.$primaryHost;
    if (!is_file($certDir.'/fullchain.pem') || !is_file($certDir.'/privkey.pem')) {
        return $hostFallbackBlock;
    }
    return "    ssl_certificate ".$certDir."/fullchain.pem;\n"
        ."    ssl_certificate_key ".$certDir."/privkey.pem;\n"
        ."    include /etc/letsencrypt/options-ssl-nginx.conf;\n"
        ."    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;\n";
}
