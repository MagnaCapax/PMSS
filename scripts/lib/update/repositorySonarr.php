<?php
/** Sonarr scoped-key installation and legacy trust retirement. @license GPL-3.0-only */

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
    $dryRun = pmssEnvFlagEnabled('PMSS_DRY_RUN');
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
        if (!is_string($content) || $content === ''
            || preg_match('/^[ \t]*[^#\r\n].*(sonarr|nzbdrone)/im', $content) !== 1) {
            continue;
        }

        $lines = preg_split('/\r?\n/', $content);
        $updatedLines = array_map(static function (string $line) use ($keyPath): string {
            return pmssSonarrSourceLineWithSignedBy($line, $keyPath);
        }, $lines);
        if ($updatedLines === $lines) {
            continue;
        }

        // preg_split retains the final empty element, so joining preserves a trailing newline.
        $updatedContent = implode(PHP_EOL, $updatedLines);

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
