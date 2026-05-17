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
