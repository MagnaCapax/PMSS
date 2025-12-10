#!/usr/bin/php
<?php
/**
 * storageHealth.php
 *
 * Operator-facing wrapper for the storage health snapshot helper under
 * scripts/util/storageHealthSnapshot.php so root's PATH can use
 * /scripts/storageHealth.php as the primary entrypoint.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__.'/util/storageHealthSnapshot.php';

