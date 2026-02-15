#!/usr/bin/env php
<?php
/**
 * Nightly cleanup for the PMSS user database.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

$debug = in_array('--debug', $argv ?? ($_SERVER['argv'] ?? []), true);

require_once __DIR__.'/../lib/users.php';

$db = new users();
$removed = $db->prune();
$after = count($db->getUsers());

if ($removed > 0) {
    echo date('c').": removed {$removed} stale user(s); {$after} remain.\n";
} elseif ($debug) {
    echo date('c').": database already in sync.\n";
}
