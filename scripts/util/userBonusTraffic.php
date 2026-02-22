#!/usr/bin/env php
<?php
require_once '/scripts/lib/user/bonusTraffic.php';

exit(function_exists('pmssUserBonusTrafficCli') ? pmssUserBonusTrafficCli($argv ?? ($_SERVER['argv'] ?? [])) : 1);
