<?php
/**
 * Helpers for managing APT sources during updates.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/distro.php';
require_once __DIR__.'/../runtime.php';

/**
 * Load an APT sources template from the config directory.
 */
function pmssLoadRepoTemplate(string $codename, ?callable $logger = null): string
{
    $log = $logger ?: 'logMessage';
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
        $log(@file_put_contents($backup, $content, LOCK_EX) === false
            ? "[WARN] Target sources path is a directory for $label and backup write failed, skipping update"
            : "[WARN] Target sources path is a directory for $label, wrote backup and skipped update");
        return false;
    }

    $current = @file_get_contents($target);
    $dir = dirname($target);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        $log("[ERROR] Unable to create parent directory for $label sources.list: $dir");
        return false;
    }
    if ($current !== false) {
        $log(@file_put_contents($backup, $current, LOCK_EX) === false
            ? "[WARN] Unable to create backup $backup before updating $label"
            : "Backup for sources.list written to $backup");
    }

    if (@file_put_contents($target, $content, LOCK_EX) === false) {
        $log("[ERROR] Failed to write sources.list for $label, attempting restore");
        if ($current !== false && @file_put_contents($target, $current, LOCK_EX) === false) {
            $log("[WARN] Failed to restore previous sources.list for $label");
        }
        return false;
    }

    return true;
}

/**
 * Persist the APT override that disables Release timestamp validation.
 */
function pmssAptWriteValidUntilOverride(?callable $logger = null, ?string $path = null): bool
{
    $log = $logger ?: 'logMessage';
    $target = $path ?: pmssResolvePathFromEnv(
        'PMSS_APT_VALID_UNTIL_OVERRIDE_PATH',
        '/etc/apt/apt.conf.d/90ignore-release-date'
    );
    $dir = dirname($target);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        $log('[WARN] Unable to create apt.conf.d directory for Release timestamp override: '.$dir);
        return false;
    }

    if (@file_put_contents($target, "Acquire::Check-Valid-Until \"false\";\n", LOCK_EX) === false) {
        $log('[WARN] Unable to write Release timestamp override: '.$target);
        return false;
    }

    return true;
}

/**
 * Run `apt-get clean` and log failures without aborting the update.
 *
 * @param callable|null $runner Optional runner returning `array{rc:int,output:string}`.
 */
function pmssAptRunClean(?callable $logger = null, ?callable $runner = null): bool
{
    $log = $logger ?: 'logMessage';
    if ($runner === null) {
        $runner = static function (): array {
            $output = [];
            $rc = 1;
            @exec('apt-get clean 2>&1', $output, $rc);

            return [
                'rc' => (int) $rc,
                'output' => trim(implode("\n", $output)),
            ];
        };
    }

    $result = $runner();
    $rc = isset($result['rc']) ? (int) $result['rc'] : 1;
    $output = isset($result['output']) ? trim((string) $result['output']) : '';
    if ($rc !== 0) {
        $suffix = ($output === '') ? '' : ' ('.$output.')';
        $log('[WARN] apt-get clean failed with rc '.$rc.$suffix);
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

    if ($distroVersion <= 0) { $log(sprintf('Skipping repository update for %s: unknown version', $distroName)); return; }
    if ($distroName === 'debian') {
        pmssUpdateAptSourcesDebian($distroVersion, $currentHash, $repos, $log);
        return;
    }

    $log($distroName === 'ubuntu' ? 'Ubuntu is not supported yet.' : "Unsupported distro: $distroName");
}

function pmssUpdateAptSourcesDebian(int $version, string $currentHash, array $repos, callable $log): void
{
    $target = pmssDebianReleaseSpecs()[$version] ?? null;
    if (!is_array($target) || !$target['sources_template']) { $log("Unsupported Debian version: $version"); return; }
    $label = $target['label'];

    if (($template = $repos[$target['repo']] ?? '') === '') {
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
            pmssAptWriteValidUntilOverride($log);
            pmssAptRunClean($log);
        }
    }
    $log("Applied Debian {$label} repository config");
}
