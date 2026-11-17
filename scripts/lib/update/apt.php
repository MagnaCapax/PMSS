<?php
/**
 * Helpers for managing APT sources during updates.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/logging.php';
require_once __DIR__.'/distro.php';
require_once __DIR__.'/managedPath.php';
require_once __DIR__.'/../runtime.php';

require_once __DIR__.'/aptSources.php';
require_once __DIR__.'/aptMaintenance.php';

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
    if ($distroName !== 'debian') {
        $log($distroName === 'ubuntu' ? 'Ubuntu is not supported yet.' : "Unsupported distro: $distroName");
        return;
    }
    $version = $distroVersion;
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
    // EOL suites lack valid Release timestamps; retain the existing exception.
    if ($target['eol'] && !pmssTestModeEnabled()) {
        pmssAptWriteValidUntilOverride($log);
        pmssAptRunClean($log);
    } elseif ($target['eol']) {
        $log('PMSS_TEST_MODE: skipping apt conf/clean ('.$label.')');
    }
    $log("Applied Debian {$label} repository config");
}
