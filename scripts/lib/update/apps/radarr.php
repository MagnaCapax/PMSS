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

$runtimePath = dirname(__DIR__).'/runtime.php';
if (!@include_once $runtimePath) {
    if (defined('STDERR')) {
        fwrite(STDERR, sprintf('Radarr updater: missing runtime helper at %s, skipping install.', $runtimePath)."\n");
    }
    return;
}
require_once __DIR__.'/arr.php';

pmssArrUpdate([
    'app'            => 'Radarr',
    'install_path'   => '/opt/Radarr',
    'releases_url'   => 'https://api.github.com/repos/Radarr/Radarr/releases',
    'asset_pattern'  => '/Radarr\.(?:develop|master)\.([0-9.]+).*linux.*tar\.gz/i',
    'extract_dir'    => 'Radarr',
    'user_agent'     => 'PMSS-Radarr',
]);
