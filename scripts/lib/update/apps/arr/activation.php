<?php
/** ARR activation helpers; loaded through arr.php. @license GPL-3.0-only */
/**
 * Re-check activation paths immediately before replacing an installed app tree.
 */
function pmssArrReleaseActivationPathsAreSafe(string $workDir, string $workPrefix, string $extractPath, string $installPath): bool
{
    if (!pmssArrInstallPathIsSafe($installPath) || is_link($workDir) || is_link($extractPath)) {
        return false;
    }

    $workReal = function_exists('pmssPrivateTempDirRealpath')
        ? pmssPrivateTempDirRealpath($workDir, $workPrefix)
        : realpath($workDir);
    $extractReal = realpath($extractPath);
    return is_string($workReal) && is_string($extractReal) && is_dir($extractReal)
        && strpos($extractReal, rtrim($workReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) === 0;
}

/** Download, extract, and atomically activate one prepared ARR release asset. */
function pmssArrReleaseActivate(array $config, string $app, string $downloadUrl, string $assetName, callable $log): bool
{
    $workPrefix = strtolower($app).'-'; $workDir = pmssCreatePrivateTempDir($workPrefix);
    if ($workDir === null) { $log('Failed to create temporary workspace'); return false; }
    $archivePath = $workDir.'/'.$assetName; $extractPath = $workDir.'/'.$config['extract_dir']; $installPath = $config['install_path'];
    try {
        if (runCommand(sprintf('curl -sSL --fail -o %s %s', escapeshellarg($archivePath), escapeshellarg($downloadUrl))) !== 0 || !is_file($archivePath)) {
            $log('Download failed; keeping existing installation'); return false;
        }
        if (runCommand(sprintf('umask 022 && tar --no-same-owner --no-same-permissions -xzf %s -C %s', escapeshellarg($archivePath), escapeshellarg($workDir))) !== 0 || !is_dir($extractPath)) {
            $log('Extraction failed; keeping existing installation'); return false;
        }
        if (!is_dir(dirname($installPath))) {
            $log('Install parent directory missing; refusing to replace application'); return false;
        }
        // Recheck the workspace and destination before replacing the old tree.
        if (!pmssArrReleaseActivationPathsAreSafe($workDir, $workPrefix, $extractPath, $installPath)) {
            $log('Unsafe activation paths; keeping existing installation'); return false;
        }
        if (is_dir($installPath) && !pmssRootOwnedTreeRemove($installPath)) {
            $log('Failed to remove existing installation; keeping existing installation'); return false;
        }
        if (runCommand(sprintf('mv %s %s', escapeshellarg($extractPath), escapeshellarg($installPath))) !== 0 || !is_dir($installPath)) {
            $log('Failed to activate extracted release'); return false;
        }
        return true;
    } finally {
        pmssRemovePrivateTempDir($workDir, $workPrefix, 'Cleaning '.$app.' updater workspace', $log, static function (string $description, string $command): int { return runCommand($command); });
    }
}
