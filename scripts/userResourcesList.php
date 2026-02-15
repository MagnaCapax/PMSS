#!/usr/bin/env php
<?php
/**
 * userResourcesList.php [--json|--jsonl]
 *
 * Operator-facing wrapper for the resource summary helper under
 * scripts/util/userResourcesList.php. Keeps the main entrypoint under
 * /scripts while the implementation remains in util/.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__.'/util/userResourcesList.php';
