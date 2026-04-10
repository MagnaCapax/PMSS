<?php
/**
 * Prowlarr installer/maintainer.
 *
 * Delegates to the shared Starr helper to fetch, unpack, and install the
 * latest Prowlarr release when a newer version is available.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/arr.php';

pmssArrUpdate([
    'app'            => 'Prowlarr',
    'install_path'   => '/opt/Prowlarr',
    'releases_url'   => 'https://api.github.com/repos/Prowlarr/Prowlarr/releases',
    'asset_pattern'  => '/Prowlarr\.(?:develop|master)\.([0-9.]+).*linux.*tar\.gz/i',
    'extract_dir'    => 'Prowlarr',
    'user_agent'     => 'PMSS-Prowlarr',
]);
