<?php
/**
 * Read-only package-state probes for update modules.
 *
 * Package installation is owned by dpkg selections in environment.php. Keep this
 * module free of queueing or install orchestration so there is one package
 * authority during update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Return the dpkg status string for a package or an empty string when missing.
 */
function pmssPackageStatus(string $package): string
{
    $cmd = 'dpkg-query -W -f=\'${Status}\' '.escapeshellarg($package).' 2>/dev/null';
    exec($cmd, $output, $rc);
    return $rc === 0 ? trim($output[0] ?? '') : '';
}

/** Return the installed version for a package, or an empty string when absent. */
function pmssPackageVersion(string $package): string
{
    $cmd = 'dpkg-query -W -f=\'${Version}\' '.escapeshellarg($package).' 2>/dev/null';
    exec($cmd, $output, $rc);
    return $rc === 0 ? trim($output[0] ?? '') : '';
}

/** List packages whose daemon binaries can be skewed by an in-place Docker update. */
function pmssDockerRuntimePackages(): array
{
    return ['containerd.io', 'docker-ce', 'docker-ce-rootless-extras'];
}

/** Snapshot versions of the Docker runtime packages for an update-phase comparison. */
function pmssDockerPackageVersions(): array
{
    $versions = [];
    foreach (pmssDockerRuntimePackages() as $package) {
        $versions[$package] = pmssPackageVersion($package);
    }

    return $versions;
}

/** Return whether any tracked Docker runtime package changed between snapshots. */
function pmssDockerPackageVersionsChanged(array $before, array $after): bool
{
    foreach (pmssDockerRuntimePackages() as $package) {
        if (($before[$package] ?? '') !== ($after[$package] ?? '')) {
            return true;
        }
    }

    return false;
}

/**
 * Determine if a package is available in the current apt cache.
 */
function pmssPackageAvailable(string $package): bool
{
    static $cache = [];
    static $availableSet = null;

    if (array_key_exists($package, $cache)) {
        return $cache[$package];
    }

    if ($availableSet === null) {
        $out = [];
        exec('apt-cache pkgnames 2>/dev/null', $out, $rc);
        $availableSet = [];
        if ($rc === 0 && !empty($out)) {
            foreach ($out as $name) {
                $name = strtolower(trim($name));
                if ($name !== '') {
                    $availableSet[$name] = true;
                }
            }
        }
    }

    $nameOnly = strtolower((string) preg_replace('/:.+$/', '', $package));
    if (!empty($availableSet)) {
        return $cache[$package] = isset($availableSet[$nameOnly]);
    }

    $cmd = 'apt-cache policy '.escapeshellarg($package).' 2>/dev/null';
    exec($cmd, $output, $rc);
    if ($rc !== 0 || empty($output)) {
        return $cache[$package] = false;
    }

    foreach ($output as $line) {
        if (stripos($line, 'Candidate:') !== false) {
            return $cache[$package] = (stripos($line, '(none)') === false);
        }
    }

    return $cache[$package] = true;
}
