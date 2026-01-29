#!/usr/bin/env php
<?php
/**
 * Thin wrapper that triggers the Debian dist-upgrade helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/update/distUpgrade.php';

exit(pmssRunDistUpgrade($argv[1] ?? null));
