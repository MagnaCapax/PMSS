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

function pmssDelugeWebPortEnsure(string $username, string $home, int $delugePort): ?int
{
    $webPortFile = $home.'/.delugeWebPort';
    $existingWebPort = pmssReadRegularFileNetworkPort($webPortFile, 1024, 65000);
    if ($existingWebPort !== null) {
        pmssPortManagerAssignServicePort($username, 'deluge-web', $existingWebPort);
        return $existingWebPort;
    }

    $preferredWebPort = $delugePort + 1;
    $preferredWebPort = pmssNetworkPortInRange($preferredWebPort, PMSS_PORT_MANAGER_MIN_PORT, PMSS_PORT_MANAGER_MAX_PORT)
        ? $preferredWebPort
        : null;
    $webPort = pmssPortManagerAssignServicePort($username, 'deluge-web', $preferredWebPort);
    if ($webPort === null || !pmssWriteUserFile($webPortFile, (string) $webPort, $username, 0644)) {
        return null;
    }

    return $webPort;
}
