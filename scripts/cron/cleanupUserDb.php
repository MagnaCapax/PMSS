#!/usr/bin/env php
<?php
/**
 * Nightly cleanup for the PMSS user database.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/users.php';

[$debug] = pmssCliArgvDebugSplit($argv ?? null);

$db = new users();
$removed = $db->prune();

if ($removed > 0) {
    echo date('c').": removed {$removed} stale user(s); ".count($db->getUsers())." remain.\n";
} elseif ($debug) {
    echo date('c').": database already in sync.\n";
}
