<?php

require_once __DIR__.'/arr.php';

// Remove legacy repo fragments to avoid apt warnings during upgrades.
@unlink('/etc/apt/sources.list.d/sonarr.list');
@passthru('apt-key del 0xA236C58F409091A18ACA53CBEBFF6B99D9B78493 2>/dev/null');

pmssArrUpdate(pmssArrAppConfig('Sonarr') ?: []);
