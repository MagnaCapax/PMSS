<?php
/**
 * Network interface name validation helpers.
 *
 * Keep interface names lexical before they reach shell arguments or sysfs path
 * joins. Linux interface names are path components, not arbitrary strings.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Normalize a Linux network interface name from trusted or probed inputs. */
function pmssNetworkInterfaceNameNormalize(string $interface, int $maxLength = 0): string
{
    // Reject malformed bytes before trim can turn them into a valid device name.
    if (strpos($interface, "\0") !== false) return '';
    $interface = trim($interface);
    if ($interface === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $interface) !== 1) {
        return '';
    }

    return $maxLength > 0 && strlen($interface) > $maxLength ? '' : $interface;
}

/** Validate an already-tokenized Linux network interface name. */
function pmssNetworkInterfaceNameIsSafe(string $interface, int $maxLength = 0): bool
{
    return pmssNetworkInterfaceNameNormalize($interface, $maxLength) === $interface;
}
