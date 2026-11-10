<?php
/** Dpkg baseline line policy and summaries; dpkgBaselineApply.php owns application. */
require_once __DIR__.'/packageState.php';
require_once __DIR__.'/logging.php';
require_once __DIR__.'/dpkgBaseline.php';

/** Mark one ignored baseline entry in the aggregate sanitizer result. */
function pmssDpkgSelectionsDrop(array &$result, string $bucket, string $package): void
{
    $result['warnings'] = true; $result[$bucket][] = $package;
}

/**
 * @param array<int,string> $lines
 * @return array{sanitised:array<int,string>,warnings:bool,short_form_seen:bool,dropped_unavailable:array<int,string>,dropped_obsolete:array<int,string>,dropped_kernel:array<int,string>,dropped_hardware:array<int,string>,started_at:float}
 */
function pmssDpkgSelectionsSanitise(array $lines, int $runtimeVersion, ?callable $packageAvailable = null, ?callable $intelDisplayPresent = null): array
{
    $packageAvailable = $packageAvailable ?: 'pmssPackageAvailable';
    $intelDisplayPresent = $intelDisplayPresent ?: 'pmssHostHasIntelDisplayPciDevice';
    $result = ['sanitised' => [], 'warnings' => false, 'short_form_seen' => false, 'dropped_unavailable' => [], 'dropped_obsolete' => [], 'dropped_kernel' => [], 'dropped_hardware' => [], 'started_at' => microtime(true)];
    foreach (pmssConfigLines($lines) as $idx => $trimmed) {
        if (($parts = pmssConfigLineColumns($trimmed, 0, [])) === []) { pmssLogStatus('WARN', sprintf('Ignoring malformed dpkg selection line %d: %s', $idx + 1, $trimmed), 0); $result['warnings'] = true; continue; }
        $package = (string) $parts[0];
        $state = (string) ($parts[1] ?? 'install');
        count($parts) === 1 && $result['short_form_seen'] = true;
        $lower = strtolower($package);
        if (($runtimeVersion >= 12 && $lower === 'wireguard-dkms') || $lower === 'repo-mediaarea') {
            $result['sanitised'][] = $package."\tdeinstall";
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
        if ($lower === 'firmware-misc-nonfree' && strtolower($state) === 'install' && !$intelDisplayPresent()) {
            pmssDpkgSelectionsDrop($result, 'dropped_hardware', $package);
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
    foreach (['dropped_kernel' => 'Baseline: dropped %d versioned kernel packages', 'dropped_obsolete' => 'Baseline: dropped %d obsolete entries (legacy names)', 'dropped_hardware' => 'Baseline: dropped %d packages without required hardware'] as $bucket => $message) {
        !empty($result[$bucket]) && pmssLogStatus('SKIP', sprintf($message, count($result[$bucket])), 0, 0.0);
    }
    !empty($result['short_form_seen']) && pmssLogStatus('INFO', 'Baseline: detected short-form dpkg selection entries; treating as \"install\" state', 0, 0.0);
}
