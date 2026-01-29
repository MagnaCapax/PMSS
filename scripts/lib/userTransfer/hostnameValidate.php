<?php
/**
 * Hostname validation for user transfers.
 *
 * @license GPL-3.0-only
 */

/**
 * Validate a hostname (or IPv4 address) without permitting shell metacharacters.
 */
function pmssUserTransferHostnameIsValid(string $hostname): bool
{
    if ($hostname === '') {
        return false;
    }
    if (preg_match('/\\s/', $hostname)) {
        return false;
    }
    if (strlen($hostname) > 253) {
        return false;
    }

    // Accept IPv4 literals to support direct node IP transfers.
    if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return true;
    }

    // Allow hostname labels separated by dots.
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/', $hostname)) {
        return false;
    }
    if (strpos($hostname, '..') !== false) {
        return false;
    }
    if ($hostname[0] === '.' || substr($hostname, -1) === '.') {
        return false;
    }

    $labels = explode('.', $hostname);
    foreach ($labels as $label) {
        if ($label === '' || strlen($label) > 63) {
            return false;
        }
        if ($label[0] === '-' || substr($label, -1) === '-') {
            return false;
        }
    }
    return true;
}

