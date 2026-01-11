#!/usr/bin/env php
<?php
/**
 * Generate MOTD using repository helper.
 *
 * Small standalone utility that invokes generateMotd() so operators and cron
 * can refresh /etc/motd without running the full updater. Follows the GNU
 * philosophy of small focused tools.
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/motd/Generator.php';

requireRoot();
\Motd::motdGenerate();
echo "MOTD refreshed.\n";
