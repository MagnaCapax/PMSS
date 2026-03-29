#!/usr/bin/env php
<?php
/**
 * Backward-compatible wrapper for the renamed permissions utility.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

$setupPermissions = __DIR__.'/setupPermissions.php';
if (!is_file($setupPermissions)) {
    fwrite(STDERR, "Error: setupPermissions.php missing; aborting wrapper.\n");
    exit(1);
}

require $setupPermissions;
