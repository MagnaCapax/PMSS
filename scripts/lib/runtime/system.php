<?php
/**
 * Terminal, root guard, and systemd helpers for the runtime facade.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssStreamIsTty($stream, bool $defaultWhenUnavailable = false): bool
{
    if (!is_resource($stream)) return $defaultWhenUnavailable;
    if (function_exists('stream_isatty')) return @stream_isatty($stream);
    if (function_exists('posix_isatty')) return @posix_isatty($stream);
    return $defaultWhenUnavailable;
}

function pmssStandardStreamsAreTty(): bool { return pmssStreamIsTty(STDIN) && pmssStreamIsTty(STDOUT) && pmssStreamIsTty(STDERR); }
function pmssSystemdRuntimeAvailable(string $runtimeDir = '/run/systemd/system'): bool { return is_dir($runtimeDir); }
function pmssSystemdUnitNameIsSafe(string $unit): bool { $unit = trim($unit); return $unit !== '' && strpos($unit, '-') !== 0 && preg_match('/^[A-Za-z0-9:_.@\\-]+$/', $unit) === 1; }
function pmssSystemdUnitDefaultServiceName(string $unit): string { return preg_match('/\.(service|socket|timer|target|mount|path|slice|scope)$/', $unit) ? $unit : $unit.'.service'; }
function pmssSystemdUnitActionNameIsSafe(string $action): bool { return isset(['disable' => true, 'enable' => true, 'mask' => true, 'reload' => true, 'restart' => true, 'start' => true, 'stop' => true, 'try-reload-or-restart' => true, 'try-restart' => true, 'unmask' => true][trim($action)]); }
function pmssSystemdUnitStateActionNameIsSafe(string $action): bool { return $action === 'is-active' || $action === 'is-enabled'; }
function pmssSystemdUnitState(string $action, string $unit): ?string { if (!pmssSystemdUnitStateActionNameIsSafe($action) || !pmssSystemdRuntimeAvailable() || !pmssSystemdUnitNameIsSafe($unit)) return null; return trim((string) @shell_exec('systemctl '.$action.' '.escapeshellarg($unit).' 2>/dev/null')); }
function pmssSystemdUnitQuietStatus(string $action, string $unit): ?bool { if (!pmssSystemdUnitStateActionNameIsSafe($action) || !pmssSystemdRuntimeAvailable() || !pmssSystemdUnitNameIsSafe($unit)) return null; exec('systemctl '.$action.' --quiet '.escapeshellarg($unit), $_, $rc); return $rc === 0; }
function pmssSystemdUnitIsActive(string $unit): ?bool { return pmssSystemdUnitQuietStatus('is-active', $unit); }
function pmssSystemdUnitIsEnabled(string $unit): ?bool { return pmssSystemdUnitQuietStatus('is-enabled', $unit); }

/** Detect the active cgroup hierarchy from explicit override and kernel state. */
function pmssCgroupMode(): string
{
    if (($override = getenv('PMSS_CGROUP_MODE')) === 'v1' || $override === 'v2') return $override;
    if (is_file('/sys/fs/cgroup/cgroup.controllers') || strpos(pmssReadRegularFileContents('/proc/self/mountinfo') ?? '', ' - cgroup2 ') !== false) return 'v2';
    if (strpos(pmssReadRegularFileContents('/proc/cmdline') ?? '', 'systemd.unified_cgroup_hierarchy=0') !== false) return 'v1';
    foreach (glob('/sys/fs/cgroup/*', GLOB_ONLYDIR) ?: [] as $dir) if (basename($dir) !== 'unified') return 'v1';
    return 'unknown';
}

function pmssCgroupModeWithDefault(string $default): string { $mode = pmssCgroupMode(); return $mode === 'unknown' ? $default : $mode; }

/**
 * Parse the current process cgroup membership into normalized entries.
 *
 * @return array<int,array{hierarchy:string,controllers:array<int,string>,path:string}>
 */
function pmssCgroupSelfEntries(string $path = '/proc/self/cgroup'): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $entries = [];
    foreach (is_array($lines) ? $lines : [] as $line) {
        $parts = explode(':', (string) $line, 3);
        if (count($parts) !== 3 || trim($parts[2]) === '') continue;
        $entries[] = ['hierarchy' => trim($parts[0]), 'controllers' => $parts[1] === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $parts[1])), 'strlen')), 'path' => '/'.ltrim(trim($parts[2]), '/')];
    }
    return $entries;
}

/** Resolve the preferred current process cgroup path for diagnostics. */
function pmssCgroupSelfPath(string $path = '/proc/self/cgroup'): string
{
    $fallback = '';
    foreach (pmssCgroupSelfEntries($path) as $entry) {
        $fallback = $entry['path'];
        if ($entry['hierarchy'] === '0') return $entry['path'];
    }
    return $fallback;
}

function requireRoot(): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        pmssError("This script must be run as root.");
        exit(1);
    }
}

function pmssError(string $message): void
{
    $prefix = pmssStreamIsTty(STDERR) ? "\033[31m[ERROR]\033[0m " : "[ERROR] ";
    fwrite(STDERR, $prefix . $message . PHP_EOL);
    logMessage('[ERROR] ' . $message);
}
