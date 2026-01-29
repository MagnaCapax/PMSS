<?php
/**
 * Radarr installer/maintainer.
 *
 * Delegates to the shared Starr helper to fetch, unpack, and install the latest
 * Radarr release when a newer version is available.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

$runtimePath = dirname(__DIR__).'/../runtime.php';
// #TODO consolidate update app bootstrap (runtime inclusion) into shared helper to avoid per-script duplication. (GH #130)
if (!@include_once $runtimePath) {
    fwrite(STDERR, "Radarr updater: missing runtime helper at {$runtimePath}, skipping install.\n");
    return;
}
require_once __DIR__.'/arr.php';

const RADARR_INSTALL_PATH   = '/opt/Radarr';
const RADARR_RELEASES_URL   = 'https://api.github.com/repos/Radarr/Radarr/releases';

pmssArrUpdate([
    'app'            => 'Radarr',
    'install_path'   => RADARR_INSTALL_PATH,
    'releases_url'   => RADARR_RELEASES_URL,
    'asset_pattern'  => '/Radarr\.(?:develop|master)\.([0-9.]+).*linux.*tar\.gz/i',
    'extract_dir'    => 'Radarr',
    'user_agent'     => 'PMSS-Radarr',
]);
