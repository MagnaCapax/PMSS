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

require_once 'lib/user/add/cli.php';

try {
    $cli = pmssAddUserParseCli($argv ?? ($_SERVER['argv'] ?? []));
} catch (InvalidArgumentException $exception) {
    die($exception->getMessage() . "\n");
}
if (!empty($cli['help'])) {
    echo $cli['usage'] . "\n";
    exit(0);
}
$user = $cli['user'];

require_once 'lib/runtime.php';
require_once 'lib/rtorrentConfig.php';
require_once 'lib/users.php';
require_once 'lib/userLifecycle.php';
require_once 'lib/user/log.php';
require_once 'lib/homeMount.php';
require_once 'lib/update.php';
require_once 'lib/update/users.php';
require_once 'lib/user/add/provisioningRuntime.php';
require_once 'lib/user/add/systemUserCreate.php';
require_once 'lib/user/add/userConfigApply.php';
require_once 'lib/user/add/artifactVerification.php';
require_once 'lib/user/add/postProvision.php';
require_once 'lib/user/add/preflight.php';

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
$usernameValidationError = pmssUsernameCreateValidationError($user['name']);
if ($usernameValidationError !== null) {
    $errorMessage = sprintf('Invalid username "%s": %s', $user['name'], $usernameValidationError['detail']);
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'add',
            'validate',
            $user['name'],
            array(
                'status'  => 'ERR',
                'code'    => $usernameValidationError['code'],
                'message' => $errorMessage,
            )
        )
    );
    if (function_exists('logProvisionMessage')) {
        logProvisionMessage('FATAL: '.$errorMessage);
    }
    if (function_exists('finalizeProvision')) {
        finalizeProvision(
            'ERROR',
            'invalid_username',
            1,
            array(
                'reason' => $usernameValidationError['code'],
                'detail' => $usernameValidationError['detail'],
            )
        );
    } elseif (function_exists('logProvisionMessage')) {
        logProvisionMessage('FATAL: Invalid username; aborting provisioning');
    }
    // Ensure automation receives a non-zero exit status for invalid input.
    fwrite(STDERR, 'ERROR: '.$errorMessage . "\n");
    exit(1);
}

$lockPath = (is_dir('/run/lock') ? '/run/lock' : '/tmp').'/pmss-addUser-'.$user['name'].'.lock';
$lockBusy = false;
$lockHandle = pmssLockFileAcquire($lockPath, true, 'c', false, true, $lockBusy);
if ($lockHandle === false) {
    logProvisionMessage("FATAL: Unable to open lock file: {$lockPath}");
    finalizeProvision('ERROR', 'lock_open_failed', 1);
    exit(1);
}
if ($lockBusy) {
    logProvisionMessage('FATAL: Another addUser is already running for this user');
    finalizeProvision('ERROR', 'lock_busy', 1);
    exit(1);
}
$homePath = "/home/{$user['name']}";
pmssAddUserEnsurePreflightState($userDb, $user, $homePath);

// Get our server hostname, and do some cleanup just to be safe
$hostname = trim( file_get_contents('/etc/hostname') );
$hostname = str_replace(array("\n", "\r", "\t"), array('','',''), $hostname);


pmssAddUserSystemUserCreate($user, $homePath);
pmssAddUserUserConfigApply($userDb, $user, $homePath);
pmssUpdateUserEnvironment($user['name']);

$userHomedirPath = $homePath;
// Execute per server additional config for user creation IF there is any
if (file_exists('/etc/seedbox/modules/basic/addUser.php')) {
    logProvisionMessage('Initiating basic module for addUser.php');
    include '/etc/seedbox/modules/basic/addUser.php';
}

// Finally start per-user services, refresh shared network state, and persist the optional traffic cap.
foreach ([
    ['Start rTorrent', sprintf('/scripts/startRtorrent %s', escapeshellarg($user['name']))],
    ['Start lighttpd', sprintf('/scripts/startLighttpd %s', escapeshellarg($user['name']))],
    ['Restart nginx', 'systemctl restart nginx || /etc/init.d/nginx restart || true'],
    ['Refresh network rules', '/scripts/util/setupNetwork.php'],
] as $step) runProvisionStep($step[0], $step[1]);
if (!empty($user['trafficLimit']) && $user['trafficLimit'] > 0) {
    $runtimeDir = '/etc/seedbox/runtime/trafficLimits';
    require_once 'lib/user/directories.php';
    if (function_exists('pmssEnsureDir')) {
        pmssEnsureDir($runtimeDir, 0700, 'root', 'root');
    } elseif (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0755, true);
        @chmod($runtimeDir, 0700);
    }
    @file_put_contents($runtimeDir."/{$user['name']}", (string) $user['trafficLimit'], LOCK_EX);
    @chmod($runtimeDir."/{$user['name']}", 0600);
    @file_put_contents("/home/{$user['name']}/.trafficLimit", (string) $user['trafficLimit'], LOCK_EX);
    @chmod("/home/{$user['name']}/.trafficLimit", 0664);
    logProvisionMessage('Traffic limit set: '.$user['trafficLimit']);
}

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
pmssAddUserVerifyArtifactsOrFail($user['name'], $homePath);

finalizeProvision('SUCCESS', 'completed', 0);
