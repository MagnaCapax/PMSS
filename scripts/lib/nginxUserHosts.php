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
require_once __DIR__.'/userTransfer.php';

/**
 * Validate that the hostname is a usable FQDN (no IPs, must contain a dot).
 */
function pmssNginxUserHostIsValidFqdn(string $hostname): bool
{
    $trimmed = strtolower(trim($hostname));
    if ($trimmed === '') {
        return false;
    }
    if (filter_var($trimmed, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return false;
    }
    if (strpos($trimmed, '.') === false) {
        return false;
    }
    return pmssUserTransferHostnameIsValid($trimmed);
}

/**
 * Read and validate the billingId stored in user homes.
 */
function pmssNginxUserBillingIdFromFile(string $path): ?string
{
    $raw = pmssReadRegularFileDigits($path);
    if ($raw === null) {
        return null;
    }
    if ((int) $raw <= 0) {
        return null;
    }
    return $raw;
}

/**
 * Build the SHA256 host prefix for a user.
 */
function pmssNginxUserHashHostname(string $username, string $billingId, string $hostname): string
{
    $seed = $username.'.'.$billingId.'.'.$hostname;
    return hash('sha256', $seed).'.'.$hostname;
}
