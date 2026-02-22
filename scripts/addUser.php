#!/usr/bin/env php
<?php
/**
 * PMSS user provisioning helper.
 *
 * Interactive helper invoked by operators and automation to provision a new
 * seedbox account. The script wraps useradd, skeleton configuration, service
 * wiring, and optional traffic limits into a single idempotent workflow so
 * freshly created users conform to the production baseline.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

// Shell-facing usage string; keep the CLI contract explicit for operators.
$usage = 'Usage: addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB [trafficLimitGB] [trafficCapMbit]';
if (empty($argv[1]) or
    empty($argv[2]) or
    empty($argv[3]) or 
    empty($argv[4]) ) die($usage . "\n");
    
$user = array(
    'name'      => $argv[1],
    'password'  => $argv[2],
    'memory'    => $argv[3],
    'quota'     => $argv[4]    
);
if (isset($argv[5])) $user['trafficLimit'] = (int) $argv[5];
if (isset($argv[6])) $user['trafficCapMbit'] = (int) $argv[6];
if ($user['password'] == 'rand') $user['password'] = '';

require_once 'lib/runtime.php';
require_once 'lib/rtorrentConfig.php';
require_once 'lib/users.php';
require_once 'lib/userLifecycle.php';
require_once 'lib/user/log.php';
require_once 'lib/homeMount.php';
require_once 'lib/user/add/provisioningRuntime.php';
require_once 'lib/user/add/runtimeInit.php';
require_once 'lib/user/add/systemUserCreate.php';
require_once 'lib/user/add/userConfigApply.php';
require_once 'lib/user/add/servicesStart.php';
require_once 'lib/user/add/trafficLimitApply.php';
require_once 'lib/user/add/postProvision.php';

// Guard: PMSS requires /home to be a separately mounted filesystem. Creating
// a user when /home is unavailable would write to the wrong location or fail
// in confusing ways. Abort early with a clear message.
pmssRequireHomeMounted('addUser.php');

$userDb = new users();

$user['name'] = pmssNormalizeUsername($user['name']);
pmssAddUserRuntimeInit();

// Provisioning runtime stats used for summary markers.
$provisionStart = microtime(true);
$provisionStats = [
    'steps'      => 0,
    'ok'         => 0,
    'err'        => 0,
    'last_error' => null,
];
$provisionFinalized = false;
if (!pmssValidateUsernameForCreate($user['name'])) {
    $detail = 'fails validation';
    if (!pmssUsernameIsValid($user['name'])) {
        $detail = 'must start with a lowercase letter and contain only lowercase letters or digits (max 8 chars)';
    } elseif (strlen($user['name']) < 3) {
        $detail = 'must be at least 3 characters long';
    } elseif (pmssUsernameIsReserved($user['name'])) {
        $detail = 'is reserved for system use';
    }
    $errorMessage = sprintf('Invalid username "%s": %s', $user['name'], $detail);
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'add',
            'validate',
            $user['name'],
            array(
                'status'  => 'ERR',
                'message' => $errorMessage,
            )
        )
    );
    if (function_exists('logProvisionMessage')) {
        logProvisionMessage('FATAL: '.$errorMessage);
    }
    if (function_exists('finalizeProvision')) {
        finalizeProvision('ERROR', 'invalid_username', 1);
    } elseif (function_exists('logProvisionMessage')) {
        logProvisionMessage('FATAL: Invalid username; aborting provisioning');
    }
    // Ensure automation receives a non-zero exit status for invalid input.
    fwrite(STDERR, 'ERROR: '.$errorMessage . "\n");
    exit(1);
}

// Prevent overlapping addUser runs for the same username to avoid UID/GID races.
$lockBase = is_dir('/run/lock') ? '/run/lock' : '/tmp';
$lockPath = $lockBase.'/pmss-addUser-'.$user['name'].'.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false) {
    logProvisionMessage("FATAL: Unable to open lock file: {$lockPath}");
    finalizeProvision('ERROR', 'lock_open_failed', 1);
    exit(1);
}
if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    logProvisionMessage('FATAL: Another addUser is already running for this user');
    finalizeProvision('ERROR', 'lock_busy', 1);
    exit(1);
}

// Preflight: reject existing accounts or orphaned home directories.
$homePath = "/home/{$user['name']}";
$userExists = false;
if (function_exists('posix_getpwnam')) {
    $pw = @posix_getpwnam($user['name']);
    $userExists = is_array($pw);
} else {
    $passwd = @file_get_contents('/etc/passwd');
    $userExists = $passwd !== false && preg_match('/^'.preg_quote($user['name'], '/').':/m', $passwd) === 1;
}
if ($userExists) {
    logProvisionMessage('FATAL: User already exists; refusing to overwrite');
    finalizeProvision('ERROR', 'user_exists', 1);
    exit(1);
}
if (is_dir($homePath)) {
    logProvisionMessage('FATAL: Home directory exists without passwd entry; refusing to clobber');
    finalizeProvision('ERROR', 'orphaned_home', 1);
    exit(1);
}

// Get our server hostname, and do some cleanup just to be safe
$hostname = trim( file_get_contents('/etc/hostname') );
$hostname = str_replace(array("\n", "\r", "\t"), array('','',''), $hostname);


pmssAddUserSystemUserCreate($user, $homePath);
pmssAddUserUserConfigApply($userDb, $user, $homePath);

$userHomedirPath = $homePath;

// User data permissions
#chdir("/home/{$user['name']}");
#passthru("chmod 777 ./ -R ; chmod 771 ."); //; su {$argv[1]} -c \"screen -fa -d -m rtorrent\" ");
#shell_exec('chown root.root /home/' . $user['name'] . '/.rtorrent.rc');
#shell_exec('chmod 775 /home/' . $user['name'] . '/.rtorrent.rc');
#shell_exec('chown root.root /home/' . $user['name'] . '/www/rutorrent/conf/*');
#shell_exec('chmod 775 /home/' . $user['name'] . '/www/rutorrent/conf/*');


// Execute per server additional config for user creation IF there is any
if (file_exists('/etc/seedbox/modules/basic/addUser.php')) {
    logProvisionMessage('Initiating basic module for addUser.php');
    include '/etc/seedbox/modules/basic/addUser.php';
}

// Finally start rTorrent for the user
pmssAddUserServicesStart($user);
pmssAddUserTrafficLimitApply($user);

// Retracker config
/*$retrackerConfigPath = $userHomedirPath . "/www/rutorrent/share/users/{$user['name']}/settings";
if (mkdir($retrackerConfigPath, 0777, true)) {
    mkdir("/home/{$user['name']}/www/rutorrent/share/users/{$user['name']}/torrents", 0777, true);
    file_put_contents($retrackerConfigPath . '/retrackers.dat', 'O:11:"rRetrackers":4:{s:4:"hash";s:14:"retrackers.dat";s:4:"list";a:1:{i:0;a:1:{i:0;s:33:"http://149.5.241.17:6969/announce";}}s:14:"dontAddPrivate";s:1:"1";s:10:"addToBegin";s:1:"1";}');
    passthru("chown {$user['name']}:{$user['name']} {$retrackerConfigPath}");
    passthru("chown {$user['name']}:{$user['name']} {$retrackerConfigPath}/retrackers.dat");
    passthru("chown {$user['name']}:{$user['name']} /home/{$user['name']}/www/rutorrent/share/users/{$user['name']}");
    passthru("chown {$user['name']}:{$user['name']} /home/{$user['name']}/www/rutorrent/share/users/{$user['name']}/torrents");
}*/


pmssAddUserPostProvision($user, $homePath);

finalizeProvision('SUCCESS', 'completed', 0);
