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

/**
     * Ensure MediaArea repository prerequisites are present.
     *
     * - Removes legacy MediaArea list files that conflict with the consolidated sources.list template.
     * - Ensures the MediaArea apt signing key is present so apt update does not fail with NO_PUBKEY.
     */
    function pmssEnsureMediaareaRepository(): void
    {
        $dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');

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
        pmssDirEnsureExists($dir, 0755);

        $tmpPath = $keyPath.'.tmp';
        @unlink($tmpPath);

        $keyUrl = 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xC10E11090EC0E438';
        $fetchCmd = pmssCommandPath('wget') !== ''
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

require_once __DIR__.'/repositoryDocker.php';
require_once __DIR__.'/repositorySonarr.php';

/**
 * Apply the appropriate sources.list template and refresh indices.
 * Returns true if apt-get update was executed.
 */
function pmssRefreshRepositories(string $distroName, int $distroVersion, ?callable $logger = null): bool
{
    pmssEnsureMediaareaRepository();
    pmssEnsureDockerRepository();
    pmssEnsureSonarrKey();
    $log = $logger ?: 'logMessage';
    $plan = pmssRepositoryUpdatePlan($distroName, $distroVersion, $log);
    $needsUpdate = $plan['mode'] === 'update';
    if ($needsUpdate) {
        pmssUpdateAptSources($distroName, (int) $distroVersion, $plan['current_hash'], $plan['templates'], $log);
    }

    $aptRc = runStep($needsUpdate ? 'Refreshing apt package index' : 'Refreshing apt package index (existing sources)', aptCmd('update'));
    if ($needsUpdate && $aptRc === 0) {
        // Touch the periodic stamp so tools like MOTD know the index is fresh
        pmssDirEnsureExists('/var/lib/apt/periodic', 0755);
        @touch('/var/lib/apt/periodic/update-success-stamp');
    } elseif ($needsUpdate) {
        logMessage('[WARN] apt-get update failed; not updating /var/lib/apt/periodic/update-success-stamp');
    }
    return true;
}
