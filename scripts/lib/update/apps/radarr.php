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

require_once __DIR__.'/arr.php';

pmssArrUpdate([
    'app'            => 'Radarr',
    'install_path'   => '/opt/Radarr',
    'releases_url'   => 'https://api.github.com/repos/Radarr/Radarr/releases',
    'asset_pattern'  => '/Radarr\.(?:develop|master)\.([0-9.]+).*linux.*tar\.gz/i',
    'extract_dir'    => 'Radarr',
    'user_agent'     => 'PMSS-Radarr',
]);
