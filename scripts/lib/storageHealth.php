<?php
/**
 * Storage health facade (SMART/NVMe + mdadm).
 *
 * Keep this include stable: scripts should require this file rather than
 * reaching into submodules. Device-specific backends stay under
 * storageHealth/ so this facade only preserves the public helper surface.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/storageHealth/common.php';
require_once __DIR__.'/storageHealth/smart.php';
require_once __DIR__.'/storageHealth/nvme.php';
require_once __DIR__.'/storageHealth/raid.php';
