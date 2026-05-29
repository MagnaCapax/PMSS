<?php
/** Dpkg baseline line policy; environment.php keeps apply orchestration. */
require_once __DIR__.'/packageState.php';
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
    $candidates = $requestedValidated && $requestedPath !== null ? [$requestedPath] : [];
    foreach (array_filter([$fallbackPath, $baseDir.'/selections.txt']) as $candidate) {
        if (!in_array($candidate, $candidates, true)) {
            $candidates[] = $candidate;
        }
    }

    $selected = null;
    foreach ($candidates as $candidate) {
        if (is_readable($candidate)) {
            $selected = $candidate;
            break;
        }
    }
    if ($selected === null) {
        return null;
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
    if (@file_put_contents($tmpSelection, implode(PHP_EOL, $sanitised).PHP_EOL, LOCK_EX) !== false) {
        return $tmpSelection;
    }
    @unlink($tmpSelection);
    logMessage('[ERROR] Unable to write temporary file for sanitized dpkg selections baseline');
    return null;
}

/** Mark one ignored baseline entry in the aggregate sanitizer result. */
function pmssDpkgSelectionsDrop(array &$result, string $bucket, string $package): void
{
    $result['warnings'] = true; $result[$bucket][] = $package;
}

/**
 * @param array<int,string> $lines
 * @return array{sanitised:array<int,string>,warnings:bool,short_form_seen:bool,dropped_unavailable:array<int,string>,dropped_obsolete:array<int,string>,dropped_kernel:array<int,string>,started_at:float}
 */
function pmssDpkgSelectionsSanitise(array $lines, int $runtimeVersion, ?callable $packageAvailable = null): array
{
    $packageAvailable = $packageAvailable ?: 'pmssPackageAvailable';
    $result = ['sanitised' => [], 'warnings' => false, 'short_form_seen' => false, 'dropped_unavailable' => [], 'dropped_obsolete' => [], 'dropped_kernel' => [], 'started_at' => microtime(true)];
    foreach ($lines as $idx => $line) {
        $trimmed = trim((string) $line);
        if ($trimmed === '') continue;
        $parts = preg_split('/\s+/', $trimmed);
        if (!is_array($parts) || $parts === []) { pmssLogStatus('WARN', sprintf('Ignoring malformed dpkg selection line %d: %s', $idx + 1, $trimmed), 0); $result['warnings'] = true; continue; }
        $package = (string) $parts[0];
        $state = isset($parts[1]) ? (string) $parts[1] : 'install';
        count($parts) === 1 && $result['short_form_seen'] = true;
        $lower = strtolower($package);
        if (($runtimeVersion >= 12 && $lower === 'wireguard-dkms') || $lower === 'repo-mediaarea') {
            $result['sanitised'][] = $package."\tdeinstall";
            pmssDpkgSelectionsDrop($result, 'dropped_obsolete', $package);
            continue;
        }
        if (in_array($lower, ['nzbdrone', 'pyload-cli'], true)) {
            pmssDpkgSelectionsDrop($result, 'dropped_obsolete', $package);
            continue;
        }
        foreach (['/^linux-image-[0-9]/i' => 'dropped_kernel', '/^php[0-9]+\.[0-9]+\-/i' => 'dropped_obsolete', '/^python3\.[0-9]+\-/i' => 'dropped_obsolete'] as $pattern => $bucket) {
            if (preg_match($pattern, $package) === 1) {
                pmssDpkgSelectionsDrop($result, $bucket, $package);
                continue 2;
            }
        }
        if (!preg_match('/^[a-z0-9.+:-]+$/i', $package) || !preg_match('/^(install|hold|purge|deinstall)$/i', $state)) {
            pmssLogStatus('WARN', sprintf('Invalid dpkg selection entry at line %d: %s', $idx + 1, $trimmed), 0);
            $result['warnings'] = true;
            continue;
        }
        if (strtolower($state) === 'install' && !$packageAvailable($package)) {
            $result['warnings'] = true;
            strtolower($package) !== 'cgroup-bin' && $result['dropped_unavailable'][] = $package;
            continue;
        }
        $result['sanitised'][] = $package."\t".strtolower($state);
    }
    return $result;
}

/** Emit the aggregate summary logs for a sanitized dpkg baseline. */
function pmssDpkgSelectionsLogSummary(array $result): void
{
    if (!empty($result['dropped_unavailable'])) {
        $sample = array_slice($result['dropped_unavailable'], 0, 10);
        pmssLogStatus('SKIP', sprintf('Baseline: dropped %d unavailable packages (first %d: %s)', count($result['dropped_unavailable']), count($sample), implode(', ', $sample)), 0, microtime(true) - (float) ($result['started_at'] ?? microtime(true)));
    }
    foreach (['dropped_kernel' => 'Baseline: dropped %d versioned kernel packages', 'dropped_obsolete' => 'Baseline: dropped %d obsolete entries (legacy names)'] as $bucket => $message) {
        !empty($result[$bucket]) && pmssLogStatus('SKIP', sprintf($message, count($result[$bucket])), 0, 0.0);
    }
    !empty($result['short_form_seen']) && pmssLogStatus('INFO', 'Baseline: detected short-form dpkg selection entries; treating as \"install\" state', 0, 0.0);
}
