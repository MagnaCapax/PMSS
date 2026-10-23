#!/usr/bin/env php
<?php
/**
 * Repair stale per-user lighttpd symlinks, then start the account service.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/../lib/userLifecycle.php';
require_once __DIR__.'/../lib/lighttpd/symlinkRepair.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';

requireRoot();
$username = $argc === 2 ? pmssUsernameNormalizeIfValid((string) $argv[1]) : null;
if ($username === null) {
    exit(pmssCliReturnWithStderr("Usage: lighttpdSymlinkRepair.php USERNAME\n"));
}
$home = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.$username;
$account = @posix_getpwnam($username);
if (!is_array($account) || !pmssPathTargetIsSafe($home, true) || !is_dir($home)
    || @fileowner($home) !== $account['uid']) {
    exit(pmssCliReturnWithStderr("Unsafe or missing account home\n"));
}

$paths = ['upload' => true, 'access.log' => false, 'error.log' => false];
$links = array_filter(array_keys($paths), static function (string $name) use ($home): bool {
    return is_link($home.'/.lighttpd/'.$name);
});
if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
    logMessage('lighttpd symlink repair dry run: user='.$username.' links='.implode(',', $links));
    exit(0);
}
if ($links !== [] && !pmssLighttpdSymlinkRepairQuotaAllows($username)) {
    exit(pmssCliReturnWithStderr("Live quota does not authorize lighttpd path repair\n"));
}
foreach ($paths as $name => $directory) {
    if (!in_array($name, $links, true)) continue;
    if (!pmssLighttpdSymlinkRepairOne($username, $home, $name, $directory)) {
        exit(pmssCliReturnWithStderr("Unable to prepare lighttpd path: {$name}\n"));
    }
}

exit(runStep('Starting repaired lighttpd account',
    pmssBuildCommand(__DIR__.'/../startLighttpd', [$username])));
