<?php
/**
 * Storage health facade (SMART/NVMe + mdadm).
 *
 * Keep this include stable: scripts should require this file rather than
 * reaching into submodules.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

foreach (['common', 'exec', 'smart', 'nvme', 'raid'] as $module) {
    require_once __DIR__.'/storageHealth/'.$module.'.php';
}
