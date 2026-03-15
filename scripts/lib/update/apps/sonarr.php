<?php
/**
 * Sonarr installer/maintainer.
 *
 * Uses the shared Starr helper to ensure the packaged Sonarr build is installed
 * and refreshes legacy apt artefacts so upgrades stay clean.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

$runtimePath = dirname(__DIR__).'/runtime.php';
if (!@include_once $runtimePath) {
    if (defined('STDERR')) {
        fwrite(STDERR, sprintf('Sonarr updater: missing runtime helper at %s, skipping install.', $runtimePath)."\n");
    }
    return;
}
require_once __DIR__.'/arr.php';

// Remove legacy repo fragments to avoid apt warnings during upgrades.
if (file_exists('/etc/apt/sources.list.d/sonarr.list')) {
    @unlink('/etc/apt/sources.list.d/sonarr.list');
}
@passthru('apt-key del 0xA236C58F409091A18ACA53CBEBFF6B99D9B78493 2>/dev/null');

pmssArrUpdate([
    'app'            => 'Sonarr',
    'install_path'   => '/opt/Sonarr',
    'releases_url'   => 'https://api.github.com/repos/Sonarr/Sonarr/releases',
    'asset_pattern'  => '/Sonarr\.(?:main|develop)\.([0-9.]+).*linux.*tar\.gz/i',
    'extract_dir'    => 'Sonarr',
    'user_agent'     => 'PMSS-Sonarr',
]);
