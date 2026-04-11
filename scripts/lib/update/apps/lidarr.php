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

pmssArrUpdateApp('Lidarr');
