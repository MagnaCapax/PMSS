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
    fwrite(STDERR, "Usage: lighttpdSymlinkRepair.php USERNAME\n");
    exit(1);
}
$home = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.$username;
$account = @posix_getpwnam($username);
if (!is_array($account) || !pmssPathTargetIsSafe($home, true) || !is_dir($home)
    || @fileowner($home) !== $account['uid']) {
    fwrite(STDERR, "Unsafe or missing account home\n");
    exit(1);
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
    fwrite(STDERR, "Live quota does not authorize lighttpd path repair\n");
    exit(1);
}
foreach ($paths as $name => $directory) {
    if (!in_array($name, $links, true)) continue;
    if (!pmssLighttpdSymlinkRepairOne($username, $home, $name, $directory)) {
        fwrite(STDERR, "Unable to prepare lighttpd path: {$name}\n");
        exit(1);
    }
}

exit(runStep('Starting repaired lighttpd account',
    pmssBuildCommand(__DIR__.'/../startLighttpd', [$username])));
