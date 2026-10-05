#!/usr/bin/env php
<?php
/**
 * Display comprehensive resource limits for all users.
 *
 * Queries live systemd slice configuration to show actual applied limits
 * for RAM, CPU, and Disk I/O.
 *
 * @author    Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../lib/user/resourcesList.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssUserResourcesListMain');
