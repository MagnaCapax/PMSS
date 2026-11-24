<?php
/** Shared inventory of name-keyed runtime residue and retained usage history.
 * @license GPL-3.0-only
 */

require_once __DIR__.'/identity.php';

/**
 * List exact paths and retirement policies; roots may be replaced by private test directories.
 * Groups select existing guarded lifecycle operations, never arbitrary shell commands.
 * @return array<string,array{action:string,paths:array}>
 */
function pmssUserNameStateInventory(string $username, array $roots = array()): array
{
    if (!pmssUsernameIsValid($username)) {
        throw new \InvalidArgumentException('Refusing unsafe username state inventory');
    }
    $roots += array(
        'iops' => pmssDirPathResolve(null, 'PMSS_RUNTIME_DIR', '/etc/seedbox/runtime'),
        'usage' => '/var/log/pmss',
        'cache' => pmssDirPathResolve(null, 'PMSS_RUNTIME_DIR', '/var/run/pmss'),
        'cron' => '/var/spool/cron/crontabs',
        'cron_legacy' => '/var/spool/cron',
        'screen' => '/var/run/screen',
        'linger' => '/var/lib/systemd/linger',
    );
    // Register new buckets here; both termination and reuse preflight consume these paths.
    $groups = array(
        'iops' => array('remove', array('iopsLimits/'.$username, 'iopsLimitEnforced/'.$username, 'iopsBudgetCap/'.$username)),
        'usage' => array('archive', array('resources/'.$username, 'traffic/'.$username, 'traffic/'.$username.'-localnet', 'traffic-ingress/'.$username)),
        'cache' => array('remove', array('resourceStats/'.$username, 'trafficStats/'.$username)),
        'cron' => array('remove', array($username)),
        'cron_legacy' => array('remove', array($username)),
        'screen' => array('remove', array('S-'.$username)),
        'linger' => array('remove', array($username)),
    );
    $inventory = array();
    foreach ($groups as $group => $spec) {
        $paths = array();
        foreach ($spec[1] as $relative) $paths[] = rtrim($roots[$group], '/').'/'.$relative;
        $inventory[$group] = array('action' => $spec[0], 'paths' => $paths);
    }
    return $inventory;
}

/** Return one operation's exact paths without duplicating its bucket list. */
function pmssUserNameStatePaths(string $username, string $group, array $roots = array()): array
{
    return pmssUserNameStateInventory($username, $roots)[$group]['paths'];
}

/** Inspect names only, including directories and dangling links; never read former account data. */
function pmssUserNameStateResidue(string $username, array $roots = array()): array
{
    $residue = array();
    foreach (pmssUserNameStateInventory($username, $roots) as $group => $spec) {
        foreach ($spec['paths'] as $path) {
            clearstatcache(true, $path);
            if (pmssPathExistsOrLink($path)) {
                $residue[] = array('group' => $group, 'action' => $spec['action'], 'path' => $path);
            }
        }
    }
    return $residue;
}
