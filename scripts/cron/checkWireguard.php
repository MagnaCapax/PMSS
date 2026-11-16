#!/usr/bin/env php
<?php
/**
 * Ensure the WireGuard service remains healthy.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/wireguard/watchdog.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssWireguardCheckMain');
