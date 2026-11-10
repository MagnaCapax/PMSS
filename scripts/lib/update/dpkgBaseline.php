<?php
/** Dpkg baseline selection and sanitized-file staging; no package commands. */
require_once __DIR__.'/logging.php';
require_once __DIR__.'/runtime/commands.php';

/**
 * Resolve the best dpkg selections baseline for the detected distro version.
 *
 * @return string|null Baseline path, or null when no baseline is readable.
 */
function pmssSelectDpkgSelectionsBaseline(?int $distroVersion = null, ?callable $logger = null): ?string
{
    $log = $logger ?: 'logMessage';
    $baseDir = __DIR__.'/dpkg';
    $baselines = [];
    foreach (glob($baseDir.'/selections-debian*.txt') ?: [] as $path) {
        if (preg_match('/selections-debian([0-9]+)\\.txt$/', $path, $match)) {
            $baselines[(int) $match[1]] = $path;
        }
    }
    $validated = array_filter(array_keys($baselines), static function (int $major): bool { return $major <= 12; });
    $latestValidated = $validated ? max($validated) : null;
    $fallbackPath = $baselines[$latestValidated ?? ($baselines ? max(array_keys($baselines)) : null)] ?? null;
    $requestedPath = $distroVersion === null ? null : ($baselines[$distroVersion] ?? sprintf('%s/selections-debian%d.txt', $baseDir, $distroVersion));
    $requestedValidated = $distroVersion === null || $distroVersion <= 12;
    $candidates = array_unique(array_filter([$requestedValidated ? $requestedPath : null, $fallbackPath, $baseDir.'/selections.txt']));

    foreach ($candidates as $selected) {
        if (!is_readable($selected)) {
            continue;
        }
        if ($distroVersion !== null && $requestedPath !== null && $selected !== $requestedPath) {
            if (!$requestedValidated && is_readable($requestedPath) && $latestValidated !== null && isset($baselines[$latestValidated]) && $selected === $baselines[$latestValidated]) {
                $log(sprintf('[WARN] Debian %d dpkg baseline exists but is not validated for automatic use; using Debian %d baseline (%s).', $distroVersion, $latestValidated, basename($selected)));
            } elseif (!$requestedValidated && is_readable($requestedPath)) {
                $log(sprintf('[WARN] Debian %d dpkg baseline exists but is not validated for automatic use; using %s.', $distroVersion, basename($selected)));
            } elseif ($latestValidated !== null && $latestValidated < $distroVersion && isset($baselines[$latestValidated]) && $selected === $baselines[$latestValidated]) {
                $log(sprintf('[WARN] Debian %d dpkg baseline missing; using Debian %d baseline (%s).', $distroVersion, $latestValidated, basename($selected)));
            } else {
                $log(sprintf('[WARN] Debian %d dpkg baseline unavailable; using %s.', $distroVersion, basename($selected)));
            }
        }
        return $selected;
    }
    return null;
}

/**
 * Persist the sanitized dpkg baseline so dpkg can read a shell redirection.
 *
 * @param array<int, string> $sanitised
 * @return string|null Temporary file path, or null when staging failed.
 */
function pmssWriteSanitisedDpkgSelectionsTempFile(array $sanitised): ?string
{
    $tmpSelection = pmssCreatePrivateTempFile('pmss-selections-');
    if ($tmpSelection === null) {
        logMessage('[ERROR] Unable to create temporary file for sanitized dpkg selections baseline');
        return null;
    }
    $payload = implode(PHP_EOL, $sanitised).PHP_EOL;
    // Never hand dpkg a truncated baseline after a short filesystem write.
    if (pmssFileWriteComplete($tmpSelection, $payload, LOCK_EX)) {
        return $tmpSelection;
    }
    @unlink($tmpSelection);
    logMessage('[ERROR] Unable to write temporary file for sanitized dpkg selections baseline');
    return null;
}
