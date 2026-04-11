#!/usr/bin/env php
<?php
require_once __DIR__.'/../lib/user/trafficLimit.php';

exit(pmssUserBonusTrafficCli($argv ?? ($_SERVER['argv'] ?? [])));
