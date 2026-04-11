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

require_once __DIR__.'/arr.php';

// Remove legacy repo fragments to avoid apt warnings during upgrades.
@unlink('/etc/apt/sources.list.d/sonarr.list');
@passthru('apt-key del 0xA236C58F409091A18ACA53CBEBFF6B99D9B78493 2>/dev/null');

pmssArrUpdateApp('Sonarr');
