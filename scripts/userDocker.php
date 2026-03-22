#!/usr/bin/env php
<?php
/**
 * userDocker.php USER ACTION
 *
 * Thin entrypoint wrapper for the per-user Docker control helper under
 * scripts/util/userDocker.php. This keeps the operator-facing path under
 * /scripts while the implementation lives in the util tree.
 *
 * Usage:
 *   /scripts/userDocker.php USER start
 *   /scripts/userDocker.php USER stop
 *   /scripts/userDocker.php USER restart
 *   /scripts/userDocker.php USER status
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/lib/runtime.php';
pmssRequireCliEntrypointScript(__DIR__, 'util/userDocker.php');
