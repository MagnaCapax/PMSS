<?php
/**
 * Distribution detection and updater self-heal helpers.
 */

require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssDetectDistro')) {
    /**
     * Detect distro name, major version, and codename with safe fallbacks.
     */
    function pmssDetectDistro(): array
    {
        $name = strtolower((string) getDistroName());
        if ($name === '') {
            $fallback = strtolower(trim((string) @shell_exec('lsb_release -is 2>/dev/null')));
            $name = $fallback !== '' ? $fallback : 'debian';
            if ($fallback === '') {
                logmsg('Could not detect distro name; defaulting to debian');
            }
        }

        $rawVersion = (string) getDistroVersion();
        if ($rawVersion === '') {
            $fallback = trim((string) @shell_exec('lsb_release -rs 2>/dev/null'));
            if ($fallback !== '') {
                $rawVersion = $fallback;
            } else {
                logmsg('Could not detect distro version; defaulting to 0');
            }
        }

        $version  = (int) filter_var($rawVersion, FILTER_SANITIZE_NUMBER_INT) ?: 0;
        $codename = getDistroCodename();
        if ($codename === '') {
            $codename = strtolower(trim((string) @shell_exec('lsb_release -cs 2>/dev/null')));
        }

        $mappedVersion = pmssVersionFromCodename($codename);
        if ($mappedVersion !== 0 && $mappedVersion !== $version) {
            if (function_exists('logmsg')) {
                logmsg(sprintf('Distro codename/version mismatch (%s vs %d); trusting codename', $codename, $version));
            }
            $version = $mappedVersion;
        } elseif ($version === 0) {
            $version = $mappedVersion;
        }

        return [
            'name'     => $name,
            'version'  => $version,
            'codename' => $codename,
        ];
    }
}

if (!function_exists('pmssVersionFromCodename')) {
    /**
     * Map Debian release codenames to their major version numbers.
     */
    function pmssVersionFromCodename(string $codename): int
    {
        static $map = [
            'jessie'   => 8,
            'stretch'  => 9,
            'buster'   => 10,
            'bullseye' => 11,
            'bookworm' => 12,
            'trixie'   => 13,
        ];

        $key = strtolower($codename);
        return isset($map[$key]) ? $map[$key] : 0;
    }
}
