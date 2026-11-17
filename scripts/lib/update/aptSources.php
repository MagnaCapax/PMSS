<?php
/** APT template discovery and backed-up source publication. @license GPL-3.0-only */

/**
 * Load an APT sources template from the config directory.
 */
function pmssLoadRepoTemplate(string $codename, ?callable $logger = null): string
{
    $log = $logger ?: 'logMessage';
    // A suite name is one filename component, never a path supplied to the reader.
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $codename) !== 1) {
        $log('[WARN] Invalid repository codename, skipping template');
        return '';
    }
    // Allow tests and recovery scripts to point at alternate config roots.
    $path = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config')."/template.sources.$codename";

    if (($data = trim((string) @file_get_contents($path))) !== '') {
        return $data."\n";
    }

    $log((file_exists($path) ? 'Repository template empty: ' : 'Repository template missing: ').$path);
    return '';
}

/**
 * Safely write /etc/apt/sources.list with a backup in case of failure.
 */
function pmssSafeWriteSources(string $content, string $label, ?callable $logger = null): bool
{
    $log = $logger ?: 'logMessage';
    $target = pmssResolvePathFromEnv('PMSS_APT_SOURCES_PATH', '/etc/apt/sources.list');
    $backup = $target.'.pmss-backup';

    if ($content === '') { $log("[WARN] Empty repository content for $label, skipping"); return false; }

    // Guard against directory targets (test overrides or misconfiguration).
    // In that case we avoid writing into the directory itself, emit a warning,
    // and persist the intended content only to the backup path so callers can
    // inspect what would have been written without touching the real tree.
    if (is_dir($target)) {
        if (!pmssUserFilePathIsSafe($backup)) {
            $log("[WARN] Target sources path is a directory for $label and backup path is unsafe, skipping update");
            return false;
        }
        $log(!pmssFileWriteComplete($backup, $content, LOCK_EX)
            ? "[WARN] Target sources path is a directory for $label and backup write failed, skipping update"
            : "[WARN] Target sources path is a directory for $label, wrote backup and skipped update");
        return false;
    }

    $dir = dirname($target);
    if (!pmssDirEnsureExists($dir, 0755)) {
        $log("[ERROR] Unable to create parent directory for $label sources.list: $dir");
        return false;
    }
    if (!pmssUserFilePathIsSafe($target)) {
        $log("[ERROR] Unsafe target path for $label sources.list: $target");
        return false;
    }

    $current = @file_get_contents($target);
    if ($current !== false) {
        if (!pmssUserFilePathIsSafe($backup)) {
            $log("[ERROR] Unsafe backup path for $label sources.list: $backup");
            return false;
        }
        $log(!pmssFileWriteComplete($backup, $current, LOCK_EX)
            ? "[WARN] Unable to create backup $backup before updating $label"
            : "Backup for sources.list written to $backup");
    }

    if (!pmssFileWriteComplete($target, $content, LOCK_EX)) {
        $log("[ERROR] Failed to write sources.list for $label, attempting restore");
        if ($current !== false && !pmssFileWriteComplete($target, $current, LOCK_EX)) {
            $log("[WARN] Failed to restore previous sources.list for $label");
        }
        return false;
    }

    return true;
}

/**
 * Build a dry-run friendly plan describing how repository configuration should evolve.
 *
 * Callers may inspect the returned structure to assert behaviour without mutating the
 * filesystem (e.g. tests verifying log flow or template selection).
 */
function pmssRepositoryUpdatePlan(string $distroName, int $distroVersion, ?callable $logger = null): array
{
    $log = $logger ?: 'logMessage';
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
