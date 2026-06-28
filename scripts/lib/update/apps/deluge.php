<?php
/**
 * Update app installer: deluge.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
// #TODO Refactor this installer to use virtualenv instead of system-wide pip. (GH #125)
// #TODO Pin Python package versions explicitly; avoid unbounded upgrades. (GH #125)
// #TODO Replace passthru/backticks with runStep wrappers for consistent logging. (GH #125)
require_once __DIR__.'/../packageState.php';
require_once __DIR__.'/remoteBinary.php';
require_once __DIR__.'/delugePatches.php';

/**
 * Legacy Debian 10 dependency set for the Deluge 2.0.5 source build.
 *
 * Keep this list stable and avoid blanket upgrades to reduce unexpected
 * global Python state drift on long-lived hosts.
 */
function pmssDelugeLegacyPipDependencyPackages(): array
{
    return [
        'twisted[tls]',
        'chardet',
        'mako',
        'pyxdg',
        'pillow',
        'slimit',
        'pygame',
        'certifi',
        'pyasn1==0.4.6',
    ];
}

/**
 * Keep Deluge command resolution anchored to distro package binaries.
 *
 * Legacy Debian 10 pip installs may leave direct binaries in /usr/local/bin.
 * On Debian 11+, those stale binaries shadow /usr/bin on PATH and keep hosts
 * pinned to old versions. This helper converges /usr/local/bin/$command to a
 * symlink targeting /usr/bin/$command whenever package-managed binaries exist.
 */
function pmssEnsureDelugeCommandSymlink(string $command, string $systemPath, string $localPath, bool $dryRun, callable $log): bool
{
    if ($command === '' || $systemPath === '' || $localPath === '') {
        return false;
    }

    if (!is_file($systemPath) || !is_executable($systemPath)) {
        $log('[WARN] Skipping Deluge command link refresh; missing system binary: '.$systemPath);
        return false;
    }

    if (is_link($localPath) && readlink($localPath) === $systemPath) {
        return true;
    }

    if (file_exists($localPath) && is_dir($localPath)) {
        $log('[WARN] Refusing to replace Deluge command directory: '.$localPath);
        return false;
    }

    if (file_exists($localPath) || is_link($localPath)) {
        if ($dryRun) {
            $log('[DRYRUN] Would replace legacy Deluge command path: '.$localPath);
            return true;
        }
        if (!@unlink($localPath)) {
            $log('[WARN] Failed to remove legacy Deluge command path: '.$localPath);
            return false;
        }
    }

    $localDir = dirname($localPath);
    if (!is_dir($localDir)) {
        if ($dryRun) {
            $log('[DRYRUN] Would create Deluge command directory: '.$localDir);
            return true;
        }
        if (!pmssDirEnsureExists($localDir, 0755)) {
            $log('[WARN] Failed to create Deluge command directory: '.$localDir);
            return false;
        }
    }

    if ($dryRun) {
        $log('[DRYRUN] Would create Deluge command symlink '.$localPath.' -> '.$systemPath);
        return true;
    }

    if (!@symlink($systemPath, $localPath)) {
        $log('[WARN] Failed to create Deluge command symlink '.$localPath.' -> '.$systemPath);
        return false;
    }

    return true;
}

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
            $removed++;
            continue;
        }

        if (is_dir($path)) {
            if (runStep('Removing legacy Deluge Python 2 artifact', 'rm -rf '.escapeshellarg($path)) === 0) {
                $removed++;
            }
            continue;
        }

        if (@unlink($path)) {
            $log('Removed legacy Deluge Python 2 artifact: '.$path);
            $removed++;
            continue;
        }

        $log('[WARN] Failed to remove legacy Deluge Python 2 artifact: '.$path);
    }

    return $removed;
}

if (pmssEnvFlagEnabled('PMSS_DELUGE_NO_ENTRYPOINT')) {
    return;
}

$delugeTarballArchive = 'deluge-2.0.5.tar.xz';
$delugeTarballUrl = 'https://ftp.osuosl.org/pub/deluge/source/2.0/'.$delugeTarballArchive;
$delugeTarballSha256 = 'c4bd04abfd211b65218be03f3c46d26f44024884de10e01859fb856fdd6f25d8';
$delugeTarballLabel = 'Deluge 2.0.5 source tarball';
$dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');
$log = 'logmsg';
if (empty($debianVersion)) $debianVersion = (string) @file_get_contents('/etc/debian_version');

echo "#### Deluge install // update\n";

// Detect currently installed Deluge version if possible.
$currentVersion = pmssAppVersionProbeMatch(['deluge-console --version 2>/dev/null'], '/deluge\s+([0-9.]+)/i', 1) ?? '';

// Debian 10 uses a pip/build route for v2.0.5; make it idempotent.
$isDebian10 = (substr($debianVersion, 0, 2) === '10');
if ($isDebian10) {
    $targetVersion = '2.0.5';
    if ($currentVersion !== $targetVersion) {
        echo "\t*** Deluge pip install (target {$targetVersion})\n";
        runStep(
            'Installing Deluge pip dependencies (no global upgrades)',
            pmssBuildCommand('pip', array_merge(['install'], pmssDelugeLegacyPipDependencyPackages()))
        );

        if (!pmssRunPinnedRemoteArchiveStep($delugeTarballLabel, $delugeTarballUrl, $delugeTarballSha256, $delugeTarballArchive, 'deluge-2.0.5', 'Extracting Deluge source', [], '/tmp')) {
            return;
        }

        runStep('Building Deluge from source', 'cd /tmp/deluge-2.0.5; python3 setup.py build; python setup.py install');
    } else {
        echo "\t*** Deluge already at target version ({$currentVersion}); skipping pip build\n";
    }
} else {
    $installed = pmssPackageStatus('deluged') === 'install ok installed' && pmssPackageStatus('deluge-web') === 'install ok installed';
    runStep(
        $installed ? 'Upgrading Deluge packages' : 'Installing Deluge packages',
        aptCmd('install -y deluged deluge-web')
    );
    runStep('Disabling deluged service', 'systemctl disable deluged || true');
}

// Debian 11+ must resolve Deluge commands to package-managed /usr/bin paths.
if (!$isDebian10) {
    foreach ([
        ['deluge-console', '/usr/bin/deluge-console', '/usr/local/bin/deluge-console'],
        ['deluge-web', '/usr/bin/deluge-web', '/usr/local/bin/deluge-web'],
        ['deluged', '/usr/bin/deluged', '/usr/local/bin/deluged'],
    ] as $commandPaths) {
        pmssEnsureDelugeCommandSymlink($commandPaths[0], $commandPaths[1], $commandPaths[2], $dryRun, $log);
    }
    pmssRemoveLegacyDelugePython2Artifacts($dryRun, $log);
}

pmssDelugePatchAll($dryRun, $log);
