<?php
/**
 * Backward-compatible bonus traffic entrypoint.
 *
 * The owning implementation lives in trafficLimit.php so both per-user GiB
 * commands share one library boundary and one CLI flow.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/trafficLimit.php';
