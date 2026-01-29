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

require_once dirname(__DIR__, 2).'/runtime.php';
require_once __DIR__.'/arr.php';

const SONARR_INSTALL_PATH   = '/opt/Sonarr';
const SONARR_RELEASES_URL   = 'https://api.github.com/repos/Sonarr/Sonarr/releases';
const SONARR_LEGACY_REPO    = '/etc/apt/sources.list.d/sonarr.list';

// Remove legacy repo fragments to avoid apt warnings during upgrades.
if (file_exists(SONARR_LEGACY_REPO)) {
    @unlink(SONARR_LEGACY_REPO);
}
@passthru('apt-key del 0xA236C58F409091A18ACA53CBEBFF6B99D9B78493 2>/dev/null');

pmssArrUpdate([
    'app'            => 'Sonarr',
    'install_path'   => SONARR_INSTALL_PATH,
    'releases_url'   => SONARR_RELEASES_URL,
    'asset_pattern'  => '/Sonarr\.(?:main|develop)\.([0-9.]+).*linux.*tar\.gz/i',
    'extract_dir'    => 'Sonarr',
    'user_agent'     => 'PMSS-Sonarr',
]);
