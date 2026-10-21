<?php
/**
 * Generate account and service credentials from the shared character set.
 *
 * @license GPL-3.0-only
 */

/** Select every character independently; account edges stay alphanumeric. */
function pmssCredentialGenerate(int $length, bool $alphanumericEdges = false): string
{
    $alphanumeric = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $alphabet = $alphanumeric.'-_';
    $credential = '';
    for ($position = 0; $position < $length; $position++) {
        $characters = $alphanumericEdges && ($position === 0 || $position === $length - 1)
            ? $alphanumeric : $alphabet;
        $credential .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $credential;
}

/** Generate a fixed-length account credential for the reset path. */
function pmssAccountPasswordGenerate(int $length = 16): string
{
    if ($length < 2) {
        throw new InvalidArgumentException('Account credential length must be at least two');
    }
    return pmssCredentialGenerate($length, true);
}
