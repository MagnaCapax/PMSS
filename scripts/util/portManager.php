#!/usr/bin/env php
<?php
/**
 * CLI wrapper for the shared PMSS service-port allocator.
 *
 * Keep this path stable for provisioning scripts and operators; reusable port
 * manager logic lives in `scripts/lib/portManager.php`.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/portManager.php';

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssPortManagerMain');
