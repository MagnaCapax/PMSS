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
    $cli = pmssAddUserParseCli(pmssCliArgv($argv ?? null));
} catch (InvalidArgumentException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
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
require_once 'lib/user/trafficLimit.php';
require_once 'lib/user/add/provisioningRuntime.php';
require_once 'lib/user/add/failureRollback.php';
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
    pmssAddUserFatalExit(
        'ERROR',
        $errorMessage,
        'invalid_username',
        array(
            'reason' => $usernameValidationError['code'],
            'detail' => $usernameValidationError['detail'],
        ),
        'ERROR: '.$errorMessage
    );
}

$lockPath = (is_dir('/run/lock') ? '/run/lock' : '/tmp').'/pmss-addUser-'.$user['name'].'.lock';
$lockBusy = false;
$lockHandle = pmssLockFileAcquire($lockPath, true, 'c', false, true, $lockBusy);
if ($lockHandle === false) {
    pmssAddUserFatalExit('ERROR', "Unable to open lock file: {$lockPath}", 'lock_open_failed');
}
if ($lockBusy) {
    pmssAddUserFatalExit('ERROR', 'Another addUser is already running for this user', 'lock_busy');
}
$homePath = "/home/{$user['name']}";
pmssAddUserEnsurePreflightState($userDb, $user, $homePath);
pmssAddUserFailureRollbackInit($userDb, $user['name'], $homePath);

pmssAddUserSystemUserCreate($user, $homePath);
pmssAddUserUserConfigApply($userDb, $user, $homePath);
if (!pmssUpdateUserEnvironment($user['name'])) {
    pmssAddUserFatalExit(
        'FAIL',
        'User web-root convergence failed; aborting provisioning',
        'web_root_convergence_failed'
    );
}

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
    $targetModes = pmssTrafficLimitCliTargetModes($user['name'], $homePath);
    $persistError = null;
    $persisted = pmssTrafficLimitCliPrepareTargetModes($targetModes)
        && pmssTrafficLimitPersistTargetModes($targetModes, (int) $user['trafficLimit'], $persistError);
    logProvisionMessage($persisted
        ? 'Traffic limit set: '.$user['trafficLimit']
        : 'Traffic limit persistence failed: '.($persistError ?: 'unable to prepare runtime targets'));
}

pmssAddUserPostProvision($user, $homePath);
pmssAddUserVerifyArtifactsOrFail($user['name'], $homePath);

finalizeProvision('SUCCESS', 'completed', 0);
