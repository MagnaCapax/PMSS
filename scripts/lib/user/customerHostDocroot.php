<?php
/**
 * Customer-host document-root helpers.
 *
 * The customer-controlled file is deliberately small: one safe subdirectory
 * under ~/www, defaulting to public when absent or invalid.
 *
 * @license GPL-3.0-only
 */

const PMSS_CUSTOMER_HOST_DOCROOT_DEFAULT = 'public';
const PMSS_CUSTOMER_HOST_DOCROOT_FILE = 'www/.mcx-docroot';
const PMSS_CUSTOMER_HOST_LIGHTTPD_ALIAS = '/_pmss-customer-host-docroot/';

function pmssUserCustomerHostDocrootSubdirIsSafe(string $subdir): bool
{
    if ($subdir === '' || strlen($subdir) > 160 || $subdir[0] === '/' || strpos($subdir, "\0") !== false || strpos($subdir, '\\') !== false) {
        return false;
    }

    foreach (explode('/', $subdir) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || $segment[0] === '.') {
            return false;
        }
        if (preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/', $segment) !== 1) {
            return false;
        }
    }

    return true;
}

function pmssUserCustomerHostDocrootSubdirNormalize(string $raw): ?string
{
    $lines = preg_split('/\R/', $raw, 2);
    $subdir = trim(is_array($lines) ? (string) $lines[0] : $raw);

    return pmssUserCustomerHostDocrootSubdirIsSafe($subdir) ? $subdir : null;
}

function pmssUserCustomerHostDocrootSubdirRead(string $homeDir): string
{
    $path = rtrim($homeDir, '/').'/'.PMSS_CUSTOMER_HOST_DOCROOT_FILE;
    if (!is_file($path) || is_link($path)) {
        return PMSS_CUSTOMER_HOST_DOCROOT_DEFAULT;
    }

    $raw = @file_get_contents($path, false, null, 0, 4096);
    if (!is_string($raw)) {
        return PMSS_CUSTOMER_HOST_DOCROOT_DEFAULT;
    }

    $subdir = pmssUserCustomerHostDocrootSubdirNormalize($raw);
    return $subdir === null ? PMSS_CUSTOMER_HOST_DOCROOT_DEFAULT : $subdir;
}
