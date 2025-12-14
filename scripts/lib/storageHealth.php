<?php
/**
 * Storage health facade (SMART/NVMe + mdadm).
 *
 * Keep this include stable: scripts should require this file rather than
 * reaching into submodules.
 */

require_once __DIR__.'/storageHealth/common.php';
require_once __DIR__.'/storageHealth/exec.php';
require_once __DIR__.'/storageHealth/disks.php';
require_once __DIR__.'/storageHealth/smart.php';
require_once __DIR__.'/storageHealth/nvme.php';
require_once __DIR__.'/storageHealth/raid.php';

