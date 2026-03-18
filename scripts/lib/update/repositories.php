<?php
/**
 * Repository management helpers for update orchestration.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/apt.php';
require_once __DIR__.'/distro.php';
require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssEnsureMediaareaRepository')) {
    /**
     * Ensure MediaArea repository prerequisites are present.
     *
     * - Removes legacy MediaArea list files that conflict with the consolidated sources.list template.
     * - Ensures the MediaArea apt signing key is present so apt update does not fail with NO_PUBKEY.
     */
    function pmssEnsureMediaareaRepository(): void
    {
        $dryRun = getenv('PMSS_DRY_RUN') === '1';

        // Remove legacy MediaArea list files; they now conflict with sources.list.
        foreach (['/etc/apt/sources.list.d/mediaarea.list', '/etc/apt/sources.list.d/mediaarea.sources'] as $target) {
            if (!is_file($target)) { continue; }
            if ($dryRun) {
                logmsg('[SKIP] Would remove legacy MediaArea repository file: '.$target);
                continue;
            }
            logmsg('[INFO] Removing legacy MediaArea repository file: '.$target);
            @unlink($target) || logmsg('[WARN] Failed to unlink '.$target);
        }

        $keyPath = pmssResolvePathFromEnv('PMSS_APT_MEDIAAREA_KEY_PATH', '/etc/apt/trusted.gpg.d/mediaarea.asc');
        if (is_file($keyPath)) {
            return;
        }

        if ($dryRun) {
            runStep('Fetching MediaArea repository key', 'true');
            return;
        }

        $dir = dirname($keyPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmpPath = $keyPath.'.tmp';
        @unlink($tmpPath);

        $keyUrl = 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xC10E11090EC0E438';
        $fetchCmd = trim((string) @shell_exec('command -v wget 2>/dev/null')) !== ''
            ? sprintf('wget -qO %s %s', escapeshellarg($tmpPath), escapeshellarg($keyUrl))
            : sprintf('curl -fsSL -o %s %s', escapeshellarg($tmpPath), escapeshellarg($keyUrl));

        if (runStep('Fetching MediaArea repository key', $fetchCmd) !== 0) {
            @unlink($tmpPath);
            logmsg('[WARN] Failed to fetch MediaArea repository key; apt update may fail for mediaarea.net');
            return;
        }

        $keyData = @file_get_contents($tmpPath);
        if (!is_string($keyData) || $keyData === '' || strpos($keyData, 'BEGIN PGP PUBLIC KEY BLOCK') === false) {
            @unlink($tmpPath);
            logmsg('[WARN] MediaArea key fetch returned unexpected content; apt update may fail for mediaarea.net');
            return;
        }

        if (!@rename($tmpPath, $keyPath)) {
            // Same-dir rename is expected to be atomic; fall back to copy+unlink in odd environments.
            if (@file_put_contents($keyPath, $keyData, LOCK_EX) === false) {
                @unlink($tmpPath);
                logmsg('[WARN] Failed to persist MediaArea key to '.$keyPath);
                return;
            }
            @unlink($tmpPath);
        }
        @chmod($keyPath, 0644);
        logmsg('MediaArea apt key installed at '.$keyPath.' (fingerprint C10E11090EC0E438)');
    }
}

if (!function_exists('pmssEnsureSonarrKey')) {
    /**
     * Ensure a Sonarr deb line includes the provided signed-by option.
     */
    function pmssSonarrSourceLineWithSignedBy(string $line, string $keyPath): string
    {
        if (preg_match('/^[ \t]*#/', $line) === 1
            || preg_match('/^[ \t]*deb([ \t]+\[[^\]]*\])?[ \t]+[^#\r\n]*(sonarr|nzbdrone)/i', $line) !== 1) {
            return $line;
        }

        if (preg_match('/\[[^\]]*signed-by\s*=\s*[^\]]+\]/i', $line) === 1) {
            return $line;
        }

        if (preg_match('/^([ \t]*)deb[ \t]+\[([^\]]*)\][ \t]+(.*)$/i', $line, $match) === 1) {
            $options = trim($match[2]);
            $options = $options === '' ? 'signed-by='.$keyPath : $options.' signed-by='.$keyPath;
            return $match[1].'deb ['.$options.'] '.$match[3];
        }

        if (preg_match('/^([ \t]*)deb[ \t]+(.*)$/i', $line, $match) === 1) {
            return $match[1].'deb [signed-by='.$keyPath.'] '.$match[2];
        }

        return $line;
    }

    /**
     * Ensure Sonarr legacy apt sources use scoped keyrings instead of global trust.
     */
    function pmssEnsureSonarrKey(): void
    {
        $dryRun = getenv('PMSS_DRY_RUN') === '1';
        $keyringDir = pmssResolvePathFromEnv('PMSS_APT_KEYRING_DIR', '/etc/apt/keyrings');
        $keyPath = pmssResolvePathFromEnv('PMSS_APT_SONARR_KEY_PATH', $keyringDir.'/sonarr.gpg');
        $legacyKeyPath = pmssResolvePathFromEnv('PMSS_APT_SONARR_LEGACY_KEY_PATH', '/etc/apt/trusted.gpg.d/sonarr.gpg');
        $sourcesListDir = pmssResolvePathFromEnv('PMSS_APT_SOURCES_LIST_D_PATH', '/etc/apt/sources.list.d');

        if (!is_dir(dirname($keyPath))) {
            if ($dryRun) {
                logmsg('[SKIP] Would create Sonarr keyring directory: '.dirname($keyPath));
            } else {
                runStep('Ensuring Sonarr keyring directory exists', sprintf('install -m 0755 -d %s', escapeshellarg(dirname($keyPath))));
            }
        }

        if (!is_file($keyPath) && is_file($legacyKeyPath) && $legacyKeyPath !== $keyPath) {
            if ($dryRun) {
                logmsg('[SKIP] Would migrate Sonarr key to scoped keyring: '.$keyPath);
            } else {
                $migrated = @rename($legacyKeyPath, $keyPath);
                if (!$migrated && @copy($legacyKeyPath, $keyPath)) {
                    $migrated = @unlink($legacyKeyPath);
                }
                if ($migrated) {
                    @chmod($keyPath, 0644);
                    logmsg('Migrated Sonarr key to scoped keyring '.$keyPath);
                } else {
                    logmsg('[WARN] Failed to migrate Sonarr key from '.$legacyKeyPath.' to '.$keyPath);
                }
            }
        }

        if (!is_file($keyPath)) {
            if ($dryRun) {
                runStep('Fetching Sonarr repository key', 'true');
            } else {
                $fetch = sprintf('curl -fsSL %s | gpg --dearmor -o %s', escapeshellarg('https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xEBFF6B99D9B78493'), escapeshellarg($keyPath));
                if (runStep('Fetching Sonarr repository key', $fetch) !== 0) {
                    @unlink($keyPath);
                    logmsg('[WARN] Failed to fetch Sonarr key; Sonarr apt repo may remain unsigned');
                    return;
                }
            }
        }

        if (is_file($keyPath)) {
            @chmod($keyPath, 0644);
            logmsg('Sonarr key installed at '.$keyPath.' (fingerprint EBFF6B99D9B78493)');
        }

        $targets = [];
        $mainSources = pmssResolvePathFromEnv('PMSS_APT_SOURCES_PATH', '/etc/apt/sources.list');
        if (is_file($mainSources)) {
            $targets[] = $mainSources;
        }
        foreach (glob(rtrim($sourcesListDir, '/').'/*.list') ?: [] as $listFile) {
            if (is_file($listFile)) {
                $targets[] = $listFile;
            }
        }

        $sourcesScoped = true;
        foreach (array_values(array_unique($targets)) as $target) {
            $content = @file_get_contents($target);
            if (!is_string($content) || $content === '') {
                continue;
            }
            if (preg_match('/^[ \t]*[^#\r\n].*(sonarr|nzbdrone)/im', $content) !== 1) {
                continue;
            }

            $lines = preg_split('/\r?\n/', $content);
            $changed = false;
            foreach ($lines as $index => $line) {
                $updated = pmssSonarrSourceLineWithSignedBy($line, $keyPath);
                if ($updated !== $line) {
                    $lines[$index] = $updated;
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }

            $updatedContent = implode(PHP_EOL, $lines);
            if (substr($content, -1) === "\n" && substr($updatedContent, -1) !== "\n") {
                $updatedContent .= "\n";
            }

            if ($dryRun) {
                logmsg('[SKIP] Would scope Sonarr apt source with signed-by in '.$target);
                continue;
            }

            if (@file_put_contents($target, $updatedContent, LOCK_EX) === false) {
                $sourcesScoped = false;
                logmsg('[WARN] Failed to scope Sonarr apt source with signed-by in '.$target);
                continue;
            }

            @chmod($target, 0644);
            logmsg('Scoped Sonarr apt source with signed-by in '.$target);
        }

        if (!$sourcesScoped) {
            return;
        }

        if (is_file($legacyKeyPath) && $legacyKeyPath !== $keyPath) {
            if ($dryRun) {
                logmsg('[SKIP] Would remove legacy globally trusted Sonarr key: '.$legacyKeyPath);
            } elseif (!@unlink($legacyKeyPath)) {
                logmsg('[WARN] Failed to remove legacy globally trusted Sonarr key: '.$legacyKeyPath);
            } else {
                logmsg('Removed legacy globally trusted Sonarr key: '.$legacyKeyPath);
            }
        }

        if (!is_file($keyPath) && $dryRun) {
            logmsg('[SKIP] Sonarr key fetch skipped in dry-run mode');
        } else {
            logmsg('Sonarr key available at '.$keyPath.' for scoped apt sources');
        }
    }
}

if (!function_exists('pmssEnsureDockerRepository')) {
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
        $version  = (int) (getenv('PMSS_DISTRO_VERSION') ?: 0);
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
        $lines = preg_split('/\r?\n/', $data);
        $changed = false;
        foreach ($lines as $i => $line) {
            if (($trim = ltrim($line)) !== '' && $trim[0] !== '#') {
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
        $currentData = @file_get_contents(pmssResolvePathFromEnv('PMSS_APT_SOURCES_PATH', '/etc/apt/sources.list'));
        $currentHash = $currentData !== false ? sha1($currentData) : '';

        if ($distroVersion <= 0) {
            $log(sprintf('Repository version unresolved for %s; reusing existing sources', $distroName));
            return ['mode' => 'reuse', 'current_hash' => $currentHash, 'templates' => []];
        }

        $templates = [];
        // NOTE: Base Debian repos use sources.list templates, NOT deb822. See @docs/adr/0008-reject-deb822-apt-sources-migration.md
        foreach (['jessie', 'buster', 'bullseye', 'bookworm', 'trixie'] as $suite) {
            $templates[$suite] = pmssLoadRepoTemplate($suite, $log);
        }

        return ['mode' => 'update', 'current_hash' => $currentHash, 'templates' => $templates];
    }
}

if (!function_exists('pmssRefreshRepositories')) {
    /**
     * Apply the appropriate sources.list template and refresh indices.
     * Returns true if apt-get update was executed.
     */
    function pmssRefreshRepositories(string $distroName, int $distroVersion, ?callable $logger = null): bool
    {
        pmssEnsureMediaareaRepository();
        pmssEnsureDockerRepository();
        pmssEnsureSonarrKey();
        $plan = pmssRepositoryUpdatePlan($distroName, $distroVersion, $logger);
        $needsUpdate = $plan['mode'] !== 'reuse';
        if ($needsUpdate) {
            pmssUpdateAptSources($distroName, (int) $distroVersion, $plan['current_hash'], $plan['templates'], pmssSelectLogger($logger));
        }

        $aptRc = runStep($needsUpdate ? 'Refreshing apt package index' : 'Refreshing apt package index (existing sources)', aptCmd('update'));

        if ($needsUpdate && $aptRc === 0) {
            // Touch the periodic stamp so tools like MOTD know the index is fresh
            @mkdir('/var/lib/apt/periodic', 0755, true);
            @touch('/var/lib/apt/periodic/update-success-stamp');
        } elseif ($needsUpdate && $aptRc !== 0) {
            logMessage('[WARN] apt-get update failed; not updating /var/lib/apt/periodic/update-success-stamp');
        }
        return true;
    }
}
