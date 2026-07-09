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
