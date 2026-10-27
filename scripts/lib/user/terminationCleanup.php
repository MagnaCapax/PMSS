<?php
/**
 * Termination cleanup primitives for foreground account removal flows.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/userLifecycle.php';
require_once dirname(__DIR__).'/pathSafety.php';
require_once __DIR__.'/userConfigStore.php';
require_once __DIR__.'/pikkumiesPreinstallState.php';

pmssRequireRelativeFiles(__DIR__.'/termination', ['commands.php', 'filesystem.php', 'ports.php', 'completion.php']);

/** Build a certbot deletion only for one validated public lineage name. */
function pmssTerminateUserCertLineageDeleteCommand(string $name): ?string
{
    if (preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/D', $name) !== 1) return null;
    return 'certbot delete --non-interactive --cert-name '.escapeshellarg($name);
}
