<?php
/**
 * Repository management helpers for update orchestration.
 */

require_once __DIR__.'/apt.php';
require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssEnsureRepositoryPrerequisites')) {
    /**
     * Ensure external repositories have their prerequisites (keys/config) in place before apt update.
     */
function pmssEnsureRepositoryPrerequisites(): void
    {
        pmssEnsureDockerRepository();
        pmssDisableLegacySonarrRepository();
        // #TODO Provide a unified third-party repo bootstrap that accepts
        //       (name, url, suites, components, key-url/keyring) and writes a
        //       deb822 .sources file with signed-by keyring under
        //       /etc/apt/keyrings.
    }
}

if (!function_exists('pmssEnsureMediaareaRepository')) {
    /**
     * Legacy shim: MediaArea repository bootstrap was retired.
     *
     * Keep the function defined for backward compatibility; it intentionally
     * performs no work.
     */
    function pmssEnsureMediaareaRepository(): void
    {
        // No-op
    }
}

if (!function_exists('pmssEnsureDockerRepository')) {
    /**
     * Ensure Docker's official repository is configured via deb822 with a keyring.
     */
    function pmssEnsureDockerRepository(): void
    {
        $keyringDir = rtrim((string) (getenv('PMSS_APT_KEYRING_DIR') ?: '/etc/apt/keyrings'), '/');
        if ($keyringDir === '') { $keyringDir = '/etc/apt/keyrings'; }
        $keyringPath = $keyringDir.'/docker.gpg';
        $sourcesDir  = '/etc/apt/sources.list.d';
        $deb822Path  = $sourcesDir.'/docker.sources';

        if (!is_dir($keyringDir)) {
            runStep('Ensuring apt keyring directory exists', sprintf('install -m 0755 -d %s', escapeshellarg($keyringDir)));
        }

        if (!is_file($keyringPath)) {
            $fetchCmd = sprintf(
                'curl -fsSL %s | gpg --dearmor -o %s',
                escapeshellarg('https://download.docker.com/linux/debian/gpg'),
                escapeshellarg($keyringPath)
            );
            if (runStep('Fetching Docker repository key', $fetchCmd) === 0) {
                runStep('Setting permissions on Docker repository key', sprintf('chmod 0644 %s', escapeshellarg($keyringPath)));
            } else {
                @unlink($keyringPath);
                logmsg('[WARN] Docker key fetch failed; proceeding without writing docker.sources');
                return;
            }
        }

        $codename = getenv('PMSS_DISTRO_CODENAME') ?: '';
        $version  = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
        if ($codename === '') {
            $codename = $version === 11 ? 'bullseye' : ($version === 12 ? 'bookworm' : ($version === 13 ? 'trixie' : ''));
        }
        if ($codename === '') {
            logmsg('[WARN] Skipping Docker repository setup: unknown suite');
            return;
        }

        $arch = trim((string) @shell_exec('dpkg --print-architecture 2>/dev/null'));
        if ($arch === '') { $arch = 'amd64'; }

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
        if (is_file($legacyList)) {
            $data = @file_get_contents($legacyList);
            if ($data !== false) {
                $lines = preg_split('/\r?\n/', $data);
                $changed = false;
                foreach ($lines as $i => $line) {
                    $trim = ltrim($line);
                    if ($trim !== '' && $trim[0] !== '#') {
                        $lines[$i] = '# PMSS(disable, switched to deb822): '.$line;
                        $changed = true;
                    }
                }
                if ($changed) {
                    @file_put_contents($legacyList, implode(PHP_EOL, $lines).PHP_EOL);
                    logmsg('Disabled legacy Docker entry in docker.list');
                }
            }
        }
    }
}

if (!function_exists('pmssQueryPackageStatus')) {
    /**
     * Return dpkg status string (install ok installed, etc.) for a package.
     */
    function pmssQueryPackageStatus(string $package): string
    {
        $cmd = 'dpkg-query -W -f=${Status} '.escapeshellarg($package).' 2>/dev/null';
        exec($cmd, $output, $rc);
        return $rc === 0 && isset($output[0]) ? trim($output[0]) : '';
    }
}

if (!function_exists('pmssRepositoryUpdatePlan')) {
    /**
     * Build a dry-run friendly plan describing how repository configuration should evolve.
     *
     * Callers may inspect the returned structure to assert behaviour without mutating the
     * filesystem (e.g. tests verifying log flow or template selection).
     */
    function pmssRepositoryUpdatePlan(string $distroName, int $distroVersion, ?callable $logger = null): array
    {
        $log = pmssSelectLogger($logger);
        $sourcesPath = pmssAptSourcesPath();
        $currentData = @file_get_contents($sourcesPath);
        $currentHash = $currentData !== false ? sha1($currentData) : '';

        if ($distroVersion <= 0) {
            $log(sprintf('Repository version unresolved for %s; reusing existing sources', $distroName));
            return [
                'mode'          => 'reuse',
                'current_hash'  => $currentHash,
                'templates'     => [],
            ];
        }

        return [
            'mode'         => 'update',
            'current_hash' => $currentHash,
            'templates'    => [
                'jessie'   => loadRepoTemplate('jessie', $log),
                'buster'   => loadRepoTemplate('buster', $log),
                'bullseye' => loadRepoTemplate('bullseye', $log),
                'bookworm' => loadRepoTemplate('bookworm', $log),
                'trixie'   => loadRepoTemplate('trixie', $log),
            ],
        ];
    }
}

if (!function_exists('pmssRefreshRepositories')) {
    /**
     * Apply the appropriate sources.list template and refresh indices.
     */
    function pmssRefreshRepositories(string $distroName, int $distroVersion, ?callable $logger = null): void
    {
        pmssEnsureRepositoryPrerequisites();
        $plan = pmssRepositoryUpdatePlan($distroName, $distroVersion, $logger);
        if ($plan['mode'] === 'reuse') {
            runStep('Refreshing apt package index (existing sources)', aptCmd('update'));
            return;
        }

    $log = pmssSelectLogger($logger);
    updateAptSources($distroName, (int)$distroVersion, $plan['current_hash'], $plan['templates'], $log);
    runStep('Refreshing apt package index', aptCmd('update'));
    
    // Touch the periodic stamp so tools like MOTD know the index is fresh
    @mkdir('/var/lib/apt/periodic', 0755, true);
    @touch('/var/lib/apt/periodic/update-success-stamp');
}

function pmssAutoremovePackages(): void
    {
        runStep('Removing packages no longer required', aptCmd('autoremove -y'));
    }
}
if (!function_exists('pmssDisableLegacySonarrRepository')) {
    /**
     * Disable legacy Sonarr apt entries that cause unsigned repository errors on old hosts.
     */
    function pmssDisableLegacySonarrRepository(): void
    {
        /*
        $files = glob('/etc/apt/sources.list.d/*.list') ?: [];
        foreach ($files as $file) {
            $data = @file_get_contents($file);
            if ($data === false || stripos($file, 'sonarr') !== false || stripos((string)$data, 'apt.sonarr.tv') !== false) {
                $backup = $file.'.pmss-backup-'.date('YmdHis');
                @copy($file, $backup);
                // Comment out non-comment lines
                $lines = preg_split('/\r?\n/', (string)$data);
                $mutated = false;
                foreach ($lines as $i => $line) {
                    $trim = ltrim($line);
                    if ($trim !== '' && $trim[0] !== '#') {
                        $lines[$i] = '# PMSS(disable, legacy Sonarr): '.$line;
                        $mutated = true;
                    }
                }
                if ($mutated) {
                    @file_put_contents($file, implode(PHP_EOL, $lines).PHP_EOL);
                }
            }
        }
        */
    }
}
