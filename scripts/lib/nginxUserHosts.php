<?php
/**
 * Nginx per-user subdomain helpers.
 *
 * These helpers keep hostname validation and permalink derivation consistent
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
    return pmssUserBillingServiceIdDigitsRead($home, true);
}

/** Derive the published mcx.fi label from a billing identifier. */
function pmssMcxLabel(string $kind, string $id): string
{
    if ($kind !== 'service' && $kind !== 'customer') {
        throw new InvalidArgumentException('Invalid mcx.fi label kind');
    }
    if ($id === '' || !ctype_digit($id)) {
        throw new InvalidArgumentException('Invalid mcx.fi billing identifier');
    }
    // Billing casts IDs to integers; trimming zeroes avoids integer overflow here.
    $canonicalId = ltrim($id, '0');
    if ($canonicalId === '') $canonicalId = '0';
    return substr(hash('sha256', 'mcx.fi:'.$kind.':'.$canonicalId), 0, 16);
}

/**
 * Stable mcx.fi service hostname for a user's billing service id.
 *
 * The billing data API on web5 computes this label; the DNS builder consumes it.
 */
function pmssNginxUserMcxHostname(string $billingServiceId): string
{
    return pmssMcxLabel('service', $billingServiceId).'.mcx.fi';
}

/**
 * Certificate names for an opt-in public HTTPS request (docs/adr/0039).
 * Only a confirmed "username" request includes the per-server hostname.
 * Unreadable and legacy requests use the username-free service permalink.
 *
 * @return list<string>
 */
function pmssWebPublicCertNames(string $user, string $serverFqdn, ?string $serviceId, ?string $requestContent): array
{
    $names = trim((string) $requestContent) === 'username' ? [$user.'.'.$serverFqdn] : [];
    if ($serviceId !== null) {
        $names[] = pmssNginxUserMcxHostname($serviceId);
    }
    return $names;
}

/**
 * Stable mcx.fi CLUSTER hostname for a user's billing client id.
 *
 * The billing data API on web5 computes this label; ns0-build-mcx.php consumes it.
 * The builder publishes this label
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
    return pmssMcxLabel('customer', $billingClientId).'.mcx.fi';
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
