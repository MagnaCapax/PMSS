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
 */
require_once __DIR__.'/lib/userLifecycle.php';
require_once __DIR__.'/lib/users.php';

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

$username = trim($username);

if (!pmssValidateUsername($username)) {
    pmssUserTerminateLog(
        pmssUserTerminateContext(
            $username,
            'validate',
            array(
                'status'  => 'ERR',
                'message' => 'Rejected username due to validation failure',
            )
        )
    );
    die("Refusing to terminate invalid username: {$username}\n");
}

// Cross-check against the managed user list to avoid acting on unexpected
// system accounts. This mirrors scripts/listUsers.php behaviour without
// spawning a separate process.
$knownUsers = users::listHomeUsers();
if (!in_array($username, $knownUsers, true)) {
    pmssUserTerminateLog(
        pmssUserTerminateContext(
            $username,
            'validate',
            array(
                'status'  => 'ERR',
                'message' => 'Username not present in managed user list',
            )
        )
    );
    die("\t**** USER NOT FOUND IN MANAGED LIST ****\n\n");
}

if (!file_exists("/home/{$username}") ||
    !is_dir("/home/{$username}")) {
    pmssUserTerminateLog(
        pmssUserTerminateContext(
            $username,
            'validate',
            array(
                'status'  => 'ERR',
                'message' => 'Home directory missing',
            )
        )
    );
    die("\t**** USER HOME NOT FOUND ****\n\n");
}

// Ensure a passwd entry exists before continuing so we do not silently act on
// stray directories or stale state.
if (function_exists('posix_getpwnam') && @posix_getpwnam($username) === false) {
    pmssUserTerminateLog(
        pmssUserTerminateContext(
            $username,
            'validate',
            array(
                'status'  => 'ERR',
                'message' => 'Username not present in /etc/passwd',
            )
        )
    );
    die("Refusing to terminate {$username}: no passwd entry found\n");
}

// Invariant: refuse to operate when the resolved home directory does not
// match the expected /home/<username> prefix. This protects against path
// tricks or unexpected symlinks.
$expectedHome = "/home/{$username}";
$realHome = realpath($expectedHome);
if ($realHome === false || strpos($realHome, $expectedHome) !== 0) {
    pmssUserTerminateLog(
        pmssUserTerminateContext(
            $username,
            'invariant_home_prefix',
            array(
                'status'        => 'ERR',
                'message'       => 'Refusing to operate on unexpected home path',
                'expected_home' => $expectedHome,
                'real_home'     => $realHome,
            )
        )
    );
    die("Refusing to operate on '{$realHome}' for user {$username}\n");
}

$overallStart = microtime(true);
pmssUserTerminateLog(
    pmssUserTerminateContext(
        $username,
        'start',
        array(
            'dry_run' => $dryRun,
        )
    )
);

echo "\n\t *** TERMINATE USER:  {$username} *** \n";

while (!in_array($continue, array('Y', 'N'))) {
    echo "Do you want to continue (Y/N)? ";
    $continue = strtoupper(trim(fgets(STDIN)));
}
if ($continue == 'N') {
    pmssUserTerminateLog(
        pmssUserTerminateContext(
            $username,
            'abort',
            array(
                'status'  => 'SKIP',
                'message' => 'Operator declined confirmation',
            )
        )
    );
    die("\n");
}

echo "Terminating user {$username}\n";
pmssUserTerminateStep($username, 'kill_processes_initial', 'killall -9 -u '.escapeshellarg($username), $dryRun);

echo "\nRunning processes by user:\n";
pmssUserTerminateStep(
    $username,
    'list_processes',
    'ps aux | grep -F '.escapeshellarg($username),
    $dryRun
); // Informal purposes, if tasks running, ie. ftp ;)

sleep(3);   // Allow time for rTorrent to die

pmssUserTerminateStep($username, 'kill_processes_retry', 'killall -9 -u '.escapeshellarg($username), $dryRun);  // Sometimes things just don't dieee!

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
    pmssUserTerminateLog(
        pmssUserTerminateContext(
            $username,
            'cleanup_ports',
            array(
                'status'  => 'OK',
                'ports'   => $ports,
                'dry_run' => $dryRun,
            )
        )
    );
}

// Reset per-user slice properties so no stale limits linger (safe if slice missing)
if (function_exists('posix_getpwnam')) {
    $info = posix_getpwnam($username);
    if (is_array($info) && isset($info['uid'])) {
        $uid = (int)$info['uid'];
        // Use systemd revert to drop any drop-ins for user slice if present
        pmssUserTerminateStep(
            $username,
            'revert_slice',
            'systemctl revert '.escapeshellarg('user-'.$uid.'.slice').' 2>/dev/null || true',
            $dryRun
        );
    }
}

echo "\nDeleting user, user data and HTTP password:\n";
pmssUserTerminateStep(
    $username,
    'userdel_initial',
    'userdel '.escapeshellarg($username),
    $dryRun
);
pmssUserTerminateStep(
    $username,
    'remove_home_initial',
    'cd /home && rm -rf -- '.escapeshellarg($username),
    $dryRun
);
//passthru("htpasswd -D /etc/lighttpd/.htpasswd {$username}");
pmssUserTerminateStep(
    $username,
    'regen_nginx_user_configs',
    '/scripts/util/createNginxConfig.php',
    $dryRun
);   // Reconfig nginx
pmssUserTerminateStep(
    $username,
    'restart_nginx',
    'systemctl restart nginx || /etc/init.d/nginx restart || true',
    $dryRun
);
pmssUserTerminateStep(
    $username,
    'userdel_groupdel_retry',
    'userdel '.escapeshellarg($username),
    $dryRun
);
pmssUserTerminateStep(
    $username,
    'groupdel_retry',
    'groupdel '.escapeshellarg($username),
    $dryRun
); // If during first attempt still some process running.
                                        // Make sure by attempting again FURTHER group needs to be deleted as well
pmssUserTerminateStep(
    $username,
    'remove_screen_socket',
    'rm -rf -- '.escapeshellarg("/var/run/screen/S-{$username}"),
    $dryRun
);
pmssUserTerminateStep(
    $username,
    'remove_home_and_nginx_user',
    'rm -rf -- '.escapeshellarg("/home/{$username}").' -- '.escapeshellarg("/etc/nginx/users/{$username}"),
    $dryRun
);
pmssUserTerminateStep(
    $username,
    'release_lighttpd_port',
    '/scripts/util/portManager.php release '.escapeshellarg($username).' lighttpd',
    $dryRun
);
@unlink("/etc/nginx/users/{$username}");

$db = new users();
if (function_exists('posix_getpwnam') && posix_getpwnam($username) !== false) {
    // #TODO explore force-removal path when passwd entry lingers to keep DB in sync automatically.
    fwrite(STDERR, "Warning: {$username} still present in /etc/passwd; skipping DB removal.\n");
} else {
    $db->removeUser($username);
    pmssUserTerminateLog(
        pmssUserTerminateContext(
            $username,
            'remove_user_db',
            array(
                'status'  => 'OK',
                'dry_run' => $dryRun,
            )
        )
    );
}

// If attemps 1 and 2 failed ...
pmssUserTerminateStep($username, 'kill_processes_final', 'killall -9 -u '.escapeshellarg($username), $dryRun);
pmssUserTerminateStep(
    $username,
    'userdel_groupdel_final',
    'userdel '.escapeshellarg($username),
    $dryRun
);
pmssUserTerminateStep(
    $username,
    'groupdel_final',
    'groupdel '.escapeshellarg($username),
    $dryRun
);

// Clear any per-user crontab to avoid leaving stale cron entries for removed users.
pmssUserTerminateStep(
    $username,
    'crontab_remove',
    'crontab -r -u '.escapeshellarg($username).' || true',
    $dryRun
);

// We don't need setup network here because ... well that chain is not going to get any additional data anymore

$overallDuration = microtime(true) - $overallStart;
pmssUserTerminateLog(
    pmssUserTerminateContext(
        $username,
        'end',
        array(
            'status'            => 'OK',
            'dry_run'           => $dryRun,
            'overall_duration'  => round($overallDuration, 4),
        )
    )
);

echo "\n## Done. User termination flow completed.\n";
