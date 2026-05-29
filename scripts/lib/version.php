<?php
/**
 * PMSS version metadata helpers.
 *
 * Kept separate from the updater library so standalone utilities can read the
 * deployed PMSS version without loading the full phase-2 update stack.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Retrieve current PMSS version from the configured version file.
 *
 * @param string $versionFile Path to the version file.
 *
 * @return string The version string or "unknown" if not found.
 */
function getPmssVersion($versionFile = '/etc/seedbox/config/version') {
    foreach ([$versionFile, '/etc/seedbox/runtime/version'] as $path) {
        if ($path !== '' && is_file($path) && ($data = trim((string) @file_get_contents($path))) !== '') {
            return $data;
        }
    }

    return 'unknown';
}
