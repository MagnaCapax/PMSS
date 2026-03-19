<?php
/**
 * Distribution detection and updater self-heal helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../log.php';
require_once __DIR__.'/osRelease.php';

/**
 * Debian major → codename mapping.
 *
 * Best-effort helper for internal consumers; callers must apply their own
 * allowlists (e.g. dist-upgrade only supports 10–13 today).
 */
function pmssDebianCodenameFromMajor(int $major): string
{
    static $reverse = [8 => 'jessie', 9 => 'stretch', 10 => 'buster', 11 => 'bullseye', 12 => 'bookworm', 13 => 'trixie'];
    return $reverse[$major] ?? '';
}

/**
 * Detect distro name, major version, and codename with safe fallbacks.
 */
function pmssDetectDistro(): array
{
    $name = strtolower((string) getDistroName());
    if ($name === '' && ($name = strtolower(trim((string) @shell_exec('lsb_release -is 2>/dev/null')))) === '') {
        $name = 'debian';
        logmsg('Could not detect distro name; defaulting to debian');
    }

    $rawVersion = (string) getDistroVersion();
    if ($rawVersion === '' && ($rawVersion = trim((string) @shell_exec('lsb_release -rs 2>/dev/null'))) === '') {
        logmsg('Could not detect distro version; defaulting to 0');
    }

    $version  = (int) filter_var($rawVersion, FILTER_SANITIZE_NUMBER_INT) ?: 0;
    $codename = getDistroCodename();
    if ($codename === '') {
        $codename = strtolower(trim((string) @shell_exec('lsb_release -cs 2>/dev/null')));
    }

    $mappedVersion = pmssVersionFromCodename($codename);
    if ($mappedVersion !== 0) {
        if ($mappedVersion !== $version) {
            logmsg(sprintf('Distro codename/version mismatch (%s vs %d); trusting codename', $codename, $version));
        }
        $version = $mappedVersion;
    }

    return [
        'name'     => $name,
        'version'  => $version,
        'codename' => $codename,
    ];
}

/**
 * Map Debian release codenames to their major version numbers.
 */
function pmssVersionFromCodename(string $codename): int
{
    static $map = ['jessie' => 8, 'stretch' => 9, 'buster' => 10, 'bullseye' => 11, 'bookworm' => 12, 'trixie' => 13];
    return (int) ($map[strtolower($codename)] ?? 0);
}
