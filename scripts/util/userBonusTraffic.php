#!/usr/bin/env php
<?php
require_once '/scripts/lib/user/bonusTraffic.php';

exit(pmssUserBonusTrafficCli($argv ?? ($_SERVER['argv'] ?? [])));
