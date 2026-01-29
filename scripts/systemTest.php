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

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__.'/util/systemTest.php';
