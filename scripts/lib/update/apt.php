<?php
/**
 * Helpers for managing APT sources during updates.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/../runtime.php';

/**
 * Load an APT sources template from the config directory.
 */
function pmssLoadRepoTemplate(string $codename, ?callable $logger = null): string
{
    $log = $logger ?: 'logMessage';
    // Allow tests and recovery scripts to point at alternate config roots.
    $path = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config')."/template.sources.$codename";

    if (!file_exists($path)) { $log("Repository template missing: $path"); return ''; }
    if (($data = trim((string) @file_get_contents($path))) === '') { $log("Repository template empty: $path"); return ''; }

    return $data."\n";
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
        $log(@file_put_contents($backup, $content, LOCK_EX) === false
            ? "[WARN] Target sources path is a directory for $label and backup write failed, skipping update"
            : "[WARN] Target sources path is a directory for $label, wrote backup and skipped update");
        return false;
    }

    $current = @file_get_contents($target);
    $dir = dirname($target);
    is_dir($dir) || @mkdir($dir, 0755, true);
    if ($current !== false) {
        $log(@file_put_contents($backup, $current, LOCK_EX) === false
            ? "[WARN] Unable to create backup $backup before updating $label"
            : "Backup for sources.list written to $backup");
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
    $log = $logger ?: 'logMessage';
    // NOTE: Base Debian repos use sources.list templates, NOT deb822. See @docs/adr/0008-reject-deb822-apt-sources-migration.md
    // Do not implement `/etc/apt/sources.list.d/*.sources` for the main Debian templates
    // without explicit operator instruction/ADR. (Docker deb822 is already in use.)

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
    }
}

/**
 * Handle Debian release specific updates.
 */
function pmssUpdateAptSourcesDebian(int $version, string $currentHash, array $repos, callable $log): void
{
    static $targets = [
        8  => ['label' => 'Jessie',   'repo' => 'jessie',   'eol' => true],
        10 => ['label' => 'Buster',   'repo' => 'buster',   'eol' => true],
        11 => ['label' => 'Bullseye', 'repo' => 'bullseye', 'eol' => false],
        12 => ['label' => 'Bookworm', 'repo' => 'bookworm', 'eol' => false],
        13 => ['label' => 'Trixie',   'repo' => 'trixie',   'eol' => false],
    ];

    if (!isset($targets[$version])) { $log("Unsupported Debian version: $version"); return; }
    $target = $targets[$version];

    $label = $target['label'];
    $template = $repos[$target['repo']] ?? '';

    if ($template === '') {
        $log("{$label} template missing, leaving sources.list untouched");
        return;
    }

    if ($currentHash === sha1($template) || !pmssSafeWriteSources($template, $label, $log)) {
        $log("Debian {$label} repositories already correct");
        return;
    }

    if ($target['eol']) {
        // EOL suites lack valid Release timestamps; relax the check.
        if (defined('PMSS_TEST_MODE')) {
            $log('PMSS_TEST_MODE: skipping apt conf/clean ('.$label.')');
        } else {
            passthru("echo 'Acquire::Check-Valid-Until \"false\";' >/etc/apt/apt.conf.d/90ignore-release-date");
            passthru('apt-get clean;');
        }
    }
    $log("Applied Debian {$label} repository config");
}
