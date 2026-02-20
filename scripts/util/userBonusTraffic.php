#!/usr/bin/env php
<?php
require_once '/scripts/lib/user/bonusTraffic.php';

$argv = $argv ?? ($_SERVER['argv'] ?? []);
$exitCode = function_exists('pmssUserBonusTrafficCli') ? pmssUserBonusTrafficCli($argv) : 1;
exit($exitCode);
