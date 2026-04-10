<?php
/**
 * Lidarr installer/maintainer.
 *
 * Delegates to the shared Starr helper to fetch, unpack, and install the
 * latest Lidarr release when a newer version is available.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/arr.php';

pmssArrUpdate([
    'app'            => 'Lidarr',
    'install_path'   => '/opt/Lidarr',
    'releases_url'   => 'https://api.github.com/repos/Lidarr/Lidarr/releases',
    'asset_pattern'  => '/Lidarr\.(?:develop|master)\.([0-9.]+).*linux.*tar\.gz/i',
    'extract_dir'    => 'Lidarr',
    'user_agent'     => 'PMSS-Lidarr',
]);
