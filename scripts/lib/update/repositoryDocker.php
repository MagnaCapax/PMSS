<?php
/** Docker repository key and source convergence. @license GPL-3.0-only */

/**
 * Ensure Docker's official repository is configured via deb822 with a keyring.
 */
function pmssEnsureDockerRepository(): void
{
    $keyringDir = pmssResolvePathFromEnv('PMSS_APT_KEYRING_DIR', '/etc/apt/keyrings');
    $keyringPath = $keyringDir.'/docker.gpg';
    $sourcesDir  = '/etc/apt/sources.list.d';
    $deb822Path  = $sourcesDir.'/docker.sources';

    if (!is_dir($keyringDir)) { runStep('Ensuring apt keyring directory exists', sprintf('install -m 0755 -d %s', escapeshellarg($keyringDir))); }

    if (!is_file($keyringPath)) {
        $fetchCmd = sprintf('curl -fsSL %s | gpg --dearmor -o %s', escapeshellarg('https://download.docker.com/linux/debian/gpg'), escapeshellarg($keyringPath));
        if (runStep('Fetching Docker repository key', $fetchCmd) === 0) {
            runStep('Setting permissions on Docker repository key', sprintf('chmod 0644 %s', escapeshellarg($keyringPath)));
        } else {
            @unlink($keyringPath);
            logmsg('[WARN] Docker key fetch failed; proceeding without writing docker.sources');
            return;
        }
    }

    $codename = getenv('PMSS_DISTRO_CODENAME') ?: '';
    $version  = pmssDistroVersionFromEnv();
    // Docker apt repo is only supported for current suites (11+); keep Debian 10 and unknown versions on the legacy "skip" path.
    if ($codename === '' && $version >= 11) {
        $codename = pmssDebianCodenameFromMajor($version);
    }
    if ($codename === '') {
        logmsg('[WARN] Skipping Docker repository setup: unknown suite');
        return;
    }

    $arch = trim((string) @shell_exec('dpkg --print-architecture 2>/dev/null')) ?: 'amd64';

    $deb822 = "Types: deb\n".
             "URIs: https://download.docker.com/linux/debian\n".
             "Suites: {$codename}\n".
             "Components: stable\n".
             "Architectures: {$arch}\n".
             "Signed-By: {$keyringPath}\n";
    if (@file_put_contents($deb822Path, $deb822) !== false) {
        @chmod($deb822Path, 0644);
        logmsg('Docker deb822 source written: '.$deb822Path);
    }

    // Disable legacy docker.list entries to prevent duplicate targets.
    $legacyList = $sourcesDir.'/docker.list';
    if (($data = @file_get_contents($legacyList)) === false) { return; }
    $lines = pmssTextLines($data);
    $disabled = array_map(static function (string $line): string {
        $trim = ltrim($line);
        return $trim !== '' && $trim[0] !== '#' ? '# PMSS(disable, switched to deb822): '.$line : $line;
    }, $lines);
    if ($disabled !== $lines) {
        @file_put_contents($legacyList, implode(PHP_EOL, $disabled).PHP_EOL);
        logmsg('Disabled legacy Docker entry in docker.list');
    }
}
