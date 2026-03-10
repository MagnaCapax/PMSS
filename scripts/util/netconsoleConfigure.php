#!/usr/bin/env php
<?php
/**
 * Configure optional netconsole logging from `/etc/seedbox/config/netconsole`.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../lib/netconsole.php';

pmssNetconsoleConfigure('logMessage');
