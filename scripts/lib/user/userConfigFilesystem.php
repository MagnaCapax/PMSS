<?php
/**
 * Filesystem helpers for the userConfig orchestration entry point.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!function_exists('pmssUserConfigReadSerializedArrayFile')) {
    /**
     * Read a serialized array payload without allowing object wakeups.
     */
    function pmssUserConfigReadSerializedArrayFile(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('pmssUserConfigReadRtorrentResources')) {
    /**
     * Read optional rTorrent resource overrides from the system config path.
     *
     * Missing files keep the historic empty-array fallback. Present but invalid
     * payloads fail closed so we do not feed malformed data into config writes.
     */
    function pmssUserConfigReadRtorrentResources(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $resources = pmssUserConfigReadSerializedArrayFile($path);
        if ($resources === null) {
            throw new RuntimeException('Invalid rTorrent resource configuration: '.$path);
        }

        return $resources;
    }
}

if (!function_exists('pmssUserConfigReadRequiredFile')) {
    /**
     * Read one required regular file for userConfig without following symlinks.
     */
    function pmssUserConfigReadRequiredFile(string $path, string $label = 'required file'): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Missing '.$label.': '.$path);
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read '.$label.': '.$path);
        }

        return $contents;
    }
}
