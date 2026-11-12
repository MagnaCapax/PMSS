#!/usr/bin/env php
<?php
/**
 * Converge system permissions: provisioning trees first, service secrets last.
 * Reruns retain the same commands, diagnostics and accumulated failure status.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/update/runtime/commands.php';
require_once __DIR__.'/../lib/update/systemPermissionsTrees.php';
require_once __DIR__.'/../lib/update/systemPermissionsSecrets.php';

requireRoot();
$exitCodes = [];
pmssSystemPermissionsTreesApply($exitCodes);
pmssSystemPermissionsSecretsApply($exitCodes);
$failed = array_filter($exitCodes, static function ($rc) { return $rc !== 0; });
exit($failed ? 1 : 0);
