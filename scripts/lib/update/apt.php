<?php
/**
 * Helpers for managing APT sources during updates.
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/../runtime.php';

/**
 * Return the target path for the primary apt sources file (testable override).
 */
function pmssAptSourcesPath(): string
{
    return pmssResolvePathFromEnv('PMSS_APT_SOURCES_PATH', '/etc/apt/sources.list');
}

/**
 * Load an APT sources template from the config directory.
 */
function pmssLoadRepoTemplate(string $codename, ?callable $logger = null): string
{
    $log = pmssSelectLogger($logger);
    // Allow tests and recovery scripts to point at alternate config roots.
    $configRoot = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
    $path = $configRoot."/template.sources.$codename";

    if (!file_exists($path)) {
        $log("Repository template missing: $path");
        return '';
    }

    $data = trim((string)@file_get_contents($path));
    if ($data === '') {
        $log("Repository template empty: $path");
        return '';
    }

    return $data."\n";
}

/**
 * Safely write /etc/apt/sources.list with a backup in case of failure.
 */
function pmssSafeWriteSources(string $content, string $label, ?callable $logger = null): bool
{
    $log = pmssSelectLogger($logger);
    $target = pmssAptSourcesPath();
    $backup = $target.'.pmss-backup';

    if ($content === '') {
        $log("[WARN] Empty repository content for $label, skipping");
        return false;
    }

    // Guard against directory targets (test overrides or misconfiguration).
    // In that case we avoid writing into the directory itself, emit a warning,
    // and persist the intended content only to the backup path so callers can
    // inspect what would have been written without touching the real tree.
    if (is_dir($target)) {
        if (@file_put_contents($backup, $content, LOCK_EX) === false) {
            $log("[WARN] Target sources path is a directory for $label and backup write failed, skipping update");
        } else {
            $log("[WARN] Target sources path is a directory for $label, wrote backup and skipped update");
        }
        return false;
    }

    $current = @file_get_contents($target);
    $dir = dirname($target);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if ($current !== false && @file_put_contents($backup, $current, LOCK_EX) === false) {
        $log("[WARN] Unable to create backup $backup before updating $label");
    } elseif ($current !== false) {
        $log("Backup for sources.list written to $backup");
    }

    if (@file_put_contents($target, $content, LOCK_EX) === false) {
        $log("[ERROR] Failed to write sources.list for $label, attempting restore");
        if ($current !== false) {
            @file_put_contents($target, $current, LOCK_EX);
        }
        return false;
    }

    return true;
}

/**
 * Ensure /etc/apt/sources.list matches the recommended repository layout.
 */
function pmssUpdateAptSources(string $distroName, int $distroVersion, string $currentHash,
    array $repos, ?callable $logger = null): void
{
    $log = pmssSelectLogger($logger);
    // #TODO Convert to deb822 sources (`/etc/apt/sources.list.d/*.sources`) and
    //       use `/etc/apt/keyrings` with `signed-by` for all external repos.

    if ($distroVersion <= 0) {
        $log(sprintf('Skipping repository update for %s: unknown version', $distroName));
        return;
    }

    switch ($distroName) {
        case 'debian':
            pmssUpdateAptSourcesDebian($distroVersion, $currentHash, $repos, $log);
            return;
        case 'ubuntu':
            $log('Ubuntu is not supported yet.');
            return;
        default:
            $log("Unsupported distro: $distroName");
            return;
    }
}

/**
 * Handle Debian release specific updates.
 */
function pmssUpdateAptSourcesDebian(int $version, string $currentHash, array $repos, callable $log): void
{
    switch ($version) {
        case 8:
            pmssApplyAptTemplate('Jessie', $repos['jessie'] ?? '', $currentHash, $log, function () use ($log) {
                if (!defined('PMSS_TEST_MODE')) {
                    passthru("echo 'Acquire::Check-Valid-Until \"false\";' >/etc/apt/apt.conf.d/90ignore-release-date");
                    passthru('apt-get clean;');
                } else {
                    $log('PMSS_TEST_MODE: skipping apt conf/clean (Jessie)');
                }
            });
            return;
        case 10:
            pmssApplyAptTemplate('Buster', $repos['buster'] ?? '', $currentHash, $log, function () use ($log) {
                // EOL suites lack valid Release timestamps; relax the check.
                if (!defined('PMSS_TEST_MODE')) {
                    passthru("echo 'Acquire::Check-Valid-Until \"false\";' >/etc/apt/apt.conf.d/90ignore-release-date");
                    passthru('apt-get clean;');
                } else {
                    $log('PMSS_TEST_MODE: skipping apt conf/clean (Buster)');
                }
            });
            return;
        case 11:
            pmssApplyAptTemplate('Bullseye', $repos['bullseye'] ?? '', $currentHash, $log);
            return;
        case 12:
            pmssApplyAptTemplate('Bookworm', $repos['bookworm'] ?? '', $currentHash, $log);
            return;
        case 13:
            pmssApplyAptTemplate('Trixie', $repos['trixie'] ?? '', $currentHash, $log);
            return;
        default:
            $log("Unsupported Debian version: $version");
            return;
    }
}

/**
 * Shared routine that compares hash, writes the template, and executes optional callbacks.
 */
function pmssApplyAptTemplate(string $label, string $template, string $currentHash, callable $log, ?callable $post = null): void
{
    if ($template === '') {
        $log("{$label} template missing, leaving sources.list untouched");
        return;
    }
    $hash = sha1($template);
    if ($currentHash !== $hash && pmssSafeWriteSources($template, $label, $log)) {
        if ($post) {
            $post();
        }
        $log("Applied Debian {$label} repository config");
    } else {
        $log("Debian {$label} repositories already correct");
    }
}
