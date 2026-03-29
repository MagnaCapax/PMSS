#!/usr/bin/env php
<?php
/**
 * Terminate tenant helper.
 *
 * - Prompts for confirmation, kills user processes, and frees reserved
 *   rTorrent port assignments.
 * - Removes the home directory, screen sockets, nginx snippets, and releases
 *   lighttpd port reservations.
 *
 * Behaviour has been stable in production for years; changes here focus on
 * hardening input validation and observability without altering the high-level
 * flow. Coordinate behavioural changes with the platform team.
 *
 * @author  Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/lib/userLifecycle.php';
require_once __DIR__.'/lib/users.php';
require_once __DIR__.'/lib/homeMount.php';
require_once __DIR__.'/lib/traffic/storage.php';

// Guard: PMSS requires /home to be a separately mounted filesystem. Terminating
// a user when /home is unavailable could lead to incomplete cleanup or acting on
// stale state. Abort early with a clear message.
pmssRequireHomeMounted('terminateUser.php');

$continue = '-';
$dryRun   = false;
$usage    = "Usage: terminateUser.php [--dry-run] [--confirm] USERNAME\n";

// Basic CLI parsing to support --confirm and --dry-run while keeping legacy
// usage intact.
$args = $argv;
array_shift($args); // drop script name
$username = '';
foreach ($args as $arg) {
    if ($arg === '--confirm') {
        $continue = 'Y';
        continue;
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if ($username === '') {
        $username = $arg;
    }
}

if ($username === '') {
    die($usage . "\n");
}

$username = pmssRequireCliUsername(
    $username,
    'terminate',
    "Refusing to terminate invalid username: %s\n"
);

// Cross-check against the managed user list to avoid acting on unexpected
// system accounts. This mirrors scripts/listUsers.php behaviour without
// spawning a separate process.
$knownUsers = users::listHomeUsers();
if (!in_array($username, $knownUsers, true)) {
    pmssUserLifecycleContextLogStatusMessage('terminate', 'validate', $username, 'ERR', 'Username not present in managed user list');
    die("\t**** USER NOT FOUND IN MANAGED LIST ****\n\n");
}

if (!is_dir("/home/{$username}")) {
    pmssUserLifecycleContextLogStatusMessage('terminate', 'validate', $username, 'ERR', 'Home directory missing');
    die("\t**** USER HOME NOT FOUND ****\n\n");
}

// Ensure a passwd entry exists before continuing so we do not silently act on
// stray directories or stale state.
if (pmssUserAccountLookup($username) === null) {
    pmssUserLifecycleContextLogStatusMessage('terminate', 'validate', $username, 'ERR', 'Username not present in /etc/passwd');
    die("Refusing to terminate {$username}: no passwd entry found\n");
}

// Invariant: refuse to operate when the resolved home directory does not
// match the expected /home/<username> prefix. This protects against path
// tricks or unexpected symlinks.
$expectedHome = "/home/{$username}";
$realHome = realpath($expectedHome);
if ($realHome === false || strpos($realHome, $expectedHome) !== 0) {
    pmssUserLifecycleContextLogStatusMessage(
        'terminate',
        'invariant_home_prefix',
        $username,
        'ERR',
        'Refusing to operate on unexpected home path',
        array(
            'expected_home' => $expectedHome,
            'real_home' => $realHome,
        )
    );
    die("Refusing to operate on '{$realHome}' for user {$username}\n");
}

$overallStart = microtime(true);
pmssUserLifecycleContextLog('terminate', 'start', $username, array(
    'dry_run' => $dryRun,
));

echo "\n\t *** TERMINATE USER:  {$username} *** \n";

while (!in_array($continue, array('Y', 'N'))) {
    echo "Do you want to continue (Y/N)? ";
    $input = fgets(STDIN);
    if ($input === false) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'confirm', $username, 'ERR', 'Unable to read confirmation input (EOF). Re-run with --confirm for non-interactive use.');
        fwrite(STDERR, "Error: confirmation input unavailable (EOF). Re-run with --confirm.\n");
        exit(1);
    }
    $continue = strtoupper(trim($input));
}
if ($continue == 'N') {
    pmssUserLifecycleContextLogStatusMessage('terminate', 'abort', $username, 'SKIP', 'Operator declined confirmation');
    die("\n");
}

echo "Terminating user {$username}\n";
$killUserCommand = 'killall -9 -u '.escapeshellarg($username);
pmssUserLifecycleStep('terminate', $username, 'kill_processes_initial', $killUserCommand, $dryRun);

echo "\nRunning processes by user:\n";
pmssUserLifecycleStep('terminate', $username,
    'list_processes',
    'ps aux | grep -F '.escapeshellarg($username),
    $dryRun
); // Informal purposes, if tasks running, ie. ftp ;)

sleep(3);   // Allow time for rTorrent to die

pmssUserLifecycleStep('terminate', $username, 'kill_processes_retry', $killUserCommand, $dryRun);  // Sometimes things just don't dieee!

// Clean up reserved rTorrent ports before removing the home directory
$portFile = "/home/{$username}/.rtorrent.rc";
$ports = [];
if (file_exists($portFile)) {
    // #TODO consider parsing ports via shared helper instead of ad-hoc regex when refactoring rtorrent config handling.
    $configLines = file($portFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($configLines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] == '#') continue;
        if (preg_match('/^scgi_port\s*=\s*(?:[^:]*:)?(\d+)/i', $line, $m)) {
            $ports['scgi'] = (int)$m[1];
        } elseif (preg_match('/dht.*port.*=\s*(\d+)/i', $line, $m)) {
            $ports['dht'] = (int)$m[1];
        } elseif (preg_match('/port_range.*=\s*(\d+)/i', $line, $m)) {
            $ports['listen'] = (int)$m[1];
        }
    }
    $portsBase = '/var/lib/pmss/ports';
    foreach ($ports as $type => $port) {
        $filePath = "$portsBase/{$type}/{$port}";
        if (file_exists($filePath)) {
            unlink($filePath);
            $dir = dirname($filePath);
            if (is_dir($dir) && count(glob($dir . '/*')) === 0) rmdir($dir);
        }
    }
    if (is_dir($portsBase) && count(glob($portsBase . '/*')) === 0) {
        rmdir($portsBase);
    }
    pmssUserLifecycleContextLog('terminate', 'cleanup_ports', $username, array(
        'status'  => 'OK',
        'ports'   => $ports,
        'dry_run' => $dryRun,
    ));
}

// Reset per-user slice properties so no stale limits linger (safe if slice missing)
if (($info = pmssUserAccountLookup($username)) !== null) {
    $uid = (int)$info['uid'];
    // Use systemd revert to drop any drop-ins for user slice if present
    pmssUserLifecycleStep('terminate', $username,
        'revert_slice',
        'systemctl revert '.escapeshellarg('user-'.$uid.'.slice').' 2>/dev/null || true',
        $dryRun
    );
}

echo "\nDeleting user, user data and HTTP password:\n";

// Clear any per-user crontab before removing the account to avoid leaving stale
// cron entries behind (Debian keeps them under /var/spool/cron/crontabs/).
$crontabSpoolPaths = array(
    "/var/spool/cron/crontabs/{$username}",
    "/var/spool/cron/{$username}",
);
$userdelCommand = 'userdel '.escapeshellarg($username);
$groupdelCommand = 'groupdel '.escapeshellarg($username);
$trafficFiles = array_values(pmssTrafficDataPaths($username));
$trafficArgs = array_map('escapeshellarg', $trafficFiles);
$clearImmutableCmd = 'if command -v chattr >/dev/null 2>&1; then chattr -i '.implode(' ', $trafficArgs).' 2>/dev/null || true; fi';
foreach (array(
    array('crontab_remove', 'crontab -r -u '.escapeshellarg($username).' || true'),
    array('crontab_spool_remove', 'rm -f -- '.escapeshellarg($crontabSpoolPaths[0]).' '.escapeshellarg($crontabSpoolPaths[1]).' || true'),
    array('userdel_initial', $userdelCommand),
    array('clear_immutable_traffic', $clearImmutableCmd),
    array('remove_home_initial', 'cd /home && rm -rf -- '.escapeshellarg($username)),
) as $stepSpec) {
    pmssUserLifecycleStep('terminate', $username, $stepSpec[0], $stepSpec[1], $dryRun);
}
//passthru("htpasswd -D /etc/lighttpd/.htpasswd {$username}");
pmssUserLifecycleRefreshNginxConfig(
    'terminate',
    $username,
    $dryRun,
    'regen_nginx_user_configs',
    '/scripts/util/createNginxConfig.php',
    array(
        'systemctlStep' => 'restart_nginx',
        'initStep' => 'restart_nginx_init',
    )
);   // Reconfig nginx
foreach (array(
    array('userdel_groupdel_retry', $userdelCommand),
    array('groupdel_retry', $groupdelCommand),
    array('remove_screen_socket', 'rm -rf -- '.escapeshellarg("/var/run/screen/S-{$username}")),
    array('remove_home_and_nginx_user', 'rm -rf -- '.escapeshellarg("/home/{$username}").' -- '.escapeshellarg("/etc/nginx/users/{$username}")),
    array('release_lighttpd_port', '/scripts/util/portManager.php release '.escapeshellarg($username).' lighttpd'),
) as $stepSpec) {
    pmssUserLifecycleStep('terminate', $username, $stepSpec[0], $stepSpec[1], $dryRun);
}
@unlink("/etc/nginx/users/{$username}");

$db = new users();
if (pmssUserAccountLookup($username) !== null) {
    // #TODO explore force-removal path when passwd entry lingers to keep DB in sync automatically.
    fwrite(STDERR, "Warning: {$username} still present in /etc/passwd; skipping DB removal.\n");
} else {
    $db->removeUser($username);
    pmssUserLifecycleContextLog('terminate', 'remove_user_db', $username, array(
        'status'  => 'OK',
        'dry_run' => $dryRun,
    ));
}

// If attemps 1 and 2 failed ...
// If attempts 1 and 2 failed, keep the final cleanup order explicit.
foreach (array(
    array('kill_processes_final', $killUserCommand),
    array('userdel_groupdel_final', $userdelCommand),
    array('groupdel_final', $groupdelCommand),
    array('cleanup_lock_files', 'rm -f -- /run/lock/pmss-*-'.$username.'.lock /tmp/pmss-*-'.$username.'.lock'),
) as $stepSpec) {
    pmssUserLifecycleStep('terminate', $username, $stepSpec[0], $stepSpec[1], $dryRun);
}

// We don't need setup network here because ... well that chain is not going to get any additional data anymore

$overallDuration = microtime(true) - $overallStart;
pmssUserLifecycleContextLog('terminate', 'end', $username, array(
    'status'           => 'OK',
    'dry_run'          => $dryRun,
    'overall_duration' => round($overallDuration, 4),
));

echo "\n## Done. User termination flow completed.\n";
