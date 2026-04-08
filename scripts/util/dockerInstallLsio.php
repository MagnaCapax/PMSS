#!/usr/bin/env php
<?php
/**
 * Tenant-facing entrypoint for common LinuxServer.io container installs.
 *
 * The heavy lifting lives under scripts/lib so the skeleton wrapper can stay
 * tiny while the real implementation remains testable and shared.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/dockerInstallLsio.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssDockerInstallLsioMain');
