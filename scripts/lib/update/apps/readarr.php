<?php
/**
 * Readarr installer/maintainer.
 *
 * Delegates to the shared Starr helper to fetch, unpack, and install the
 * latest Readarr release when a newer version is available.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/arr.php';

pmssArrUpdate([
    'app'            => 'Readarr',
    'install_path'   => '/opt/Readarr',
    'releases_url'   => 'https://api.github.com/repos/Readarr/Readarr/releases',
    'asset_pattern'  => '/Readarr\.(?:develop|master)\.([0-9.]+).*linux.*tar\.gz/i',
    'extract_dir'    => 'Readarr',
    'user_agent'     => 'PMSS-Readarr',
]);
