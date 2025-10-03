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
        pmssEnsureMediaareaRepository();
        // #TODO Provide a unified third-party repo bootstrap that accepts
        //       (name, url, suites, components, key-url/keyring) and writes a
        //       deb822 .sources file with signed-by keyring under
        //       /etc/apt/keyrings.
    }
}

if (!function_exists('pmssEnsureMediaareaRepository')) {
    /**
     * MediaArea ships the latest mediainfo build; ensure its repo package is present for GPG keys.
     */
function pmssEnsureMediaareaRepository(): void
    {
        // #TODO Prefer deb822 sources and `/etc/apt/keyrings` over legacy
        //       trusted.gpg.d entries. Unify MediaArea/Docker and other
        //       third-party repos behind a single helper that manages
        //       keyrings and source files consistently.
        $status = pmssQueryPackageStatus('repo-mediaarea');
        $keyFiles = [
            '/etc/apt/trusted.gpg.d/mediaarea.gpg',
            '/etc/apt/trusted.gpg.d/mediaarea.asc',
            '/etc/apt/trusted.gpg.d/mediaarea-keyring.gpg',
        ];

        $override = getenv('PMSS_MEDIAAREA_KEY_PATHS');
        if (is_string($override) && $override !== '') {
            $candidates = array_map('trim', explode(PATH_SEPARATOR, $override));
            $candidates = array_filter($candidates, static fn($path) => $path !== '');
            if (!empty($candidates)) {
                $keyFiles = $candidates;
            }
        }

        foreach ($keyFiles as $key) {
            if (is_file($key)) {
                return;
            }
        }

        if ($status === 'install ok installed') {
            return;
        }

        $tmpDir = sys_get_temp_dir().'/pmss-mediaarea-'.bin2hex(random_bytes(6));
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true)) {
            logmsg('[WARN] Unable to create temp dir for MediaArea repository bootstrap');
            return;
        }

        $packageUrl  = 'https://mediaarea.net/repo/deb/repo-mediaarea_1.0-25_all.deb';
        $packagePath = $tmpDir.'/repo-mediaarea.deb';

        $downloadCmd = sprintf('wget -q -O %s %s', escapeshellarg($packagePath), escapeshellarg($packageUrl));
        if (runStep('Fetching MediaArea repository package', $downloadCmd) !== 0) {
            @unlink($packagePath);
            @rmdir($tmpDir);
            return;
        }

        runStep('Installing MediaArea repository package', sprintf('dpkg -i %s', escapeshellarg($packagePath)));
        @unlink($packagePath);
        @rmdir($tmpDir);
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
    }
}

if (!function_exists('pmssAutoremovePackages')) {
    /**
     * Remove packages that are no longer required.
     */
    function pmssAutoremovePackages(): void
    {
        runStep('Removing packages no longer required', aptCmd('autoremove -y'));
    }
}
