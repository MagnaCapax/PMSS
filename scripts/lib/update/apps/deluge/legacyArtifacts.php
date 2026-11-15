<?php
/** Legacy Deluge Python 2 artifact discovery and guarded cleanup. */
// @license GPL-3.0-only

/** Collect stale Python 2 Deluge artifacts that can shadow package-managed Deluge on newer Debian. */
function pmssDelugeLegacyPython2ArtifactPaths(string $root = '/usr/local/lib/python2.7/dist-packages'): array
{
    $root = rtrim($root, '/');
    if ($root === '' || strpos($root, "\0") !== false || is_link($root)) {
        return [];
    }

    $paths = [];
    foreach ([$root.'/deluge-*.egg', $root.'/deluge-*.egg-info'] as $pattern) {
        foreach (glob($pattern) ?: [] as $path) {
            if (!pmssDelugeLegacyPython2ArtifactPathIsSafe($path, $root)) {
                continue;
            }
            $paths[$path] = $path;
        }
    }

    ksort($paths);
    return array_values($paths);
}

/** Refuse cleanup candidates outside the legacy Deluge Python 2 artifact shape. */
function pmssDelugeLegacyPython2ArtifactPathIsSafe(string $path, string $root): bool
{
    $root = rtrim($root, '/');
    if ($path === '' || $root === '' || strpos($path, "\0") !== false || strpos($root, "\0") !== false) {
        return false;
    }

    return strpos($path, $root.'/deluge-') === 0
        && !is_link($path)
        && (is_file($path) || is_dir($path))
        && (substr($path, -4) === '.egg' || substr($path, -9) === '.egg-info');
}

/** Remove stale Python 2 Deluge artifacts after newer Debian uses distro packages. */
function pmssRemoveLegacyDelugePython2Artifacts(bool $dryRun, callable $log, string $root = '/usr/local/lib/python2.7/dist-packages', ?array $paths = null): int
{
    $removed = 0;
    foreach ($paths ?? pmssDelugeLegacyPython2ArtifactPaths($root) as $path) {
        if (!pmssDelugeLegacyPython2ArtifactPathIsSafe($path, $root)) {
            $log('[WARN] Refusing unsafe Deluge legacy artifact cleanup candidate: '.$path);
            continue;
        }

        if ($dryRun) {
            $log('[DRYRUN] Would remove legacy Deluge Python 2 artifact: '.$path);
        } elseif (is_dir($path)) {
            if (runStep('Removing legacy Deluge Python 2 artifact', 'rm -rf '.escapeshellarg($path)) !== 0) continue;
        } elseif (@unlink($path)) {
            $log('Removed legacy Deluge Python 2 artifact: '.$path);
        } else {
            $log('[WARN] Failed to remove legacy Deluge Python 2 artifact: '.$path);
            continue;
        }
        // Count only successful operations, including the existing dry-run result.
        $removed++;
    }

    return $removed;
}
