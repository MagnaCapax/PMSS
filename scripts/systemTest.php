#!/usr/bin/env php
<?php
/**
 * systemTest.php
 *
 * Operator-facing wrapper for the PMSS system status probe under
 * scripts/util/systemTest.php so root's PATH can use /scripts/systemTest.php
 * as the primary entrypoint.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/lib/runtime.php';

pmssPrepareCliEntrypoint();

require_once __DIR__.'/util/systemTest.php';
