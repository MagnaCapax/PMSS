#!/usr/bin/env php
<?php
/**
 * Configure WireGuard using repository helpers.
 *
 * This script is intended for orchestrated runs (update-step2) and can be
 * invoked directly for repairs. It applies configuration, writes user guides,
 * and enables the wg-quick@wg0 service.
 */

require_once __DIR__.'/../lib/update/runtime/commands.php';
require_once __DIR__.'/../lib/wireguard.php';

requireRoot();

pmssWireguardConfigure('logMessage');
