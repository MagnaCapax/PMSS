<?php
/** Deluge provisioning file publication and port adoption; loaded through deluge.php.
 * @license GPL-3.0-only
 */

/**
 * Persist a Deluge config file through the shared symlink-safe writer.
 */
function pmssDelugeConfigFileWrite(string $path, string $content, string $label, ?string $owner = null, ?int $modeOverride = null): bool
{
    $existing = @lstat($path);
    if ($owner !== null && is_array($existing) && !pmssUserFileStatIsSingleRegular($existing)) {
        pmssLogStatus('WARN', sprintf('Refusing Deluge %s config path: %s', $label, $path), 1);
        return false;
    }
    $mode = $modeOverride ?? (is_array($existing) ? ($existing['mode'] & 0777) : 0644);
    if ($owner !== null
        ? pmssWriteUserFile($path, $content, $owner, $mode)
        : pmssReplaceUserFilePreservingMetadata($path, $content, 0644)) {
        return true;
    }

    pmssLogStatus('WARN', sprintf('Failed to write Deluge %s config: %s', $label, $path), 1);
    return false;
}

/**
 * The account's Deluge web port: the root port-manager reservation. The home copy is only a
 * preference for a first reservation and is rewritten to the reserved port for display.
 */
function pmssDelugeWebPortEnsure(string $username, string $home, int $delugePort): ?int
{
    $webPortFile = $home.'/.delugeWebPort';
    $preferredWebPort = pmssReadRegularFileNetworkPort($webPortFile, 1024, 65000) ?? $delugePort + 1;
    $preferredWebPort = pmssNetworkPortInRange($preferredWebPort, PMSS_PORT_MANAGER_MIN_PORT, PMSS_PORT_MANAGER_MAX_PORT)
        ? $preferredWebPort
        : null;
    $webPort = pmssPortManagerAssignServicePort($username, 'deluge-web', $preferredWebPort);
    if ($webPort === null) {
        return null;
    }
    if (pmssReadRegularFileNetworkPort($webPortFile, 1024, 65000) !== $webPort
        && !pmssWriteUserFile($webPortFile, (string) $webPort, $username, 0644)) {
        return null;
    }

    return $webPort;
}
