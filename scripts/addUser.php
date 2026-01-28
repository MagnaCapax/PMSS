#!/usr/bin/env php
<?php
/**
 * PMSS user provisioning helper.
 *
 * Interactive helper invoked by operators and automation to provision a new
 * seedbox account. The script wraps useradd, skeleton configuration, service
 * wiring, and optional traffic limits into a single idempotent workflow so
 * freshly created users conform to the production baseline.
 */

// Shell-facing usage string; keep the CLI contract explicit for operators.
$usage = 'Usage: addUser.php USERNAME PASSWORD RAM_MiB DISK_QUOTA_GiB [trafficLimitGB]';
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
if ($user['password'] == 'rand') $user['password'] = '';

require_once 'lib/runtime.php';
require_once 'lib/rtorrentConfig.php';
require_once 'lib/users.php';
require_once 'lib/userLifecycle.php';
require_once 'lib/user/log.php';
require_once 'lib/homeMount.php';

// Guard: PMSS requires /home to be a separately mounted filesystem. Creating
// a user when /home is unavailable would write to the wrong location or fail
// in confusing ways. Abort early with a clear message.
pmssRequireHomeMounted('addUser.php');

$userDb = new users();

$user['name'] = pmssNormalizeUsername($user['name']);
if (!pmssValidateUsernameForCreate($user['name'])) {
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'add',
            'validate',
            $user['name'],
            array(
                'status'  => 'ERR',
                'message' => 'Rejected username due to validation failure',
            )
        )
    );
    if (function_exists('finalizeProvision')) {
        finalizeProvision('ERROR', 'invalid_username', 1);
    } elseif (function_exists('logProvisionMessage')) {
        logProvisionMessage('FATAL: Invalid username; aborting provisioning');
    }
    die("Invalid username: {$user['name']}\n");
}

// Avoid PHP timeouts in CLI runs and keep the process alive if the invoking
// SSH session dies mid-provision.
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
@ignore_user_abort(true);

/**
 * Best-effort detachment from a dying SSH session in non-interactive runs.
 *
 * When automation launches addUser.php without a TTY, a backend timeout can
 * close the SSH channel and deliver SIGHUP/SIGPIPE. Ignoring those signals
 * helps the provisioning continue while logs are written to disk.
 */
if (function_exists('posix_isatty')) {
    $hasTty = @posix_isatty(STDIN) || @posix_isatty(STDOUT) || @posix_isatty(STDERR);
    if (!$hasTty) {
        if (function_exists('posix_setsid')) {
            @posix_setsid();
        }
        if (function_exists('pcntl_signal')) {
            if (defined('SIGHUP')) {
                @pcntl_signal(SIGHUP, SIG_IGN);
            }
            if (defined('SIGPIPE')) {
                @pcntl_signal(SIGPIPE, SIG_IGN);
            }
        }
    }
}

// Provisioning runtime stats used for summary markers.
$provisionStart = microtime(true);
$provisionStats = [
    'steps'      => 0,
    'ok'         => 0,
    'err'        => 0,
    'last_error' => null,
];
$provisionFinalized = false;

/**
 * Append a message to the provisioning log and console for traceability.
 *
 * @param string $message Human-readable status printed for the operator.
 */
function logProvisionMessage(string $message): void
{
    global $user;
    $prefix = date('Y-m-d H:i:s') . " ({$user['name']}): ";
    @file_put_contents('/var/log/pmss/addUser.log', $prefix.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
    echo $message.PHP_EOL;
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user['name'], $message);
    }
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'add',
            'log',
            $user['name'],
            array(
                'status'  => 'INFO',
                'message' => $message,
            )
        )
    );
}

/**
 * Run a shell command and log whether it succeeded without aborting.
 * The 'continue on failure' behavior is intentional to allow as many
 * provisioning steps as possible to complete.
 *
 * @param string $description Operator-facing label describing the action.
 * @param string $command     Full shell command executed through runCommand().
 * @param string|null $logCommand Optional redacted command for logs.
 *
 * @return int Return code bubbled up from the child command.
 */
function runProvisionStep(string $description, string $command, ?string $logCommand = null): int
{
    global $user, $provisionStats;

    $logCommand = $logCommand ?? $command;
    $logger = 'logProvisionMessage';
    if ($logCommand !== $command) {
        $logger = function (string $message) use ($command, $logCommand): void {
            logProvisionMessage(str_replace($command, $logCommand, $message));
        };
    }

    $startedAt = microtime(true);
    $result = runCommand($command, false, $logger);
    $duration = round(microtime(true) - $startedAt, 4);
    $provisionStats['steps']++;
    if ($result !== 0) {
        $provisionStats['err']++;
        $provisionStats['last_error'] = $description . ' rc=' . $result;
        logProvisionMessage($description . ' failed (rc=' . $result . ', duration=' . $duration . 's)');
    } else {
        $provisionStats['ok']++;
        logProvisionMessage($description . ' completed (duration=' . $duration . 's)');
    }
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'add',
            'step',
            $user['name'],
            array(
                'status'   => $result === 0 ? 'OK' : 'ERR',
                'step'     => $description,
                'command'  => $logCommand,
                'rc'       => $result,
                'duration' => $duration,
            )
        )
    );
    return $result;
}

/**
 * Emit a single-line status marker and structured summary for operators.
 *
 * @param string $status  SUCCESS|FAIL|ERROR marker.
 * @param string $message Human-readable context (kept short for grep).
 * @param int    $exitCode Intended exit code for the summary.
 */
function finalizeProvision(string $status, string $message, int $exitCode): void
{
    global $user, $provisionStart, $provisionStats, $provisionFinalized;
    if ($provisionFinalized) {
        return;
    }
    $provisionFinalized = true;

    $duration = round(microtime(true) - $provisionStart, 3);
    $summary = sprintf(
        '###ADDUSER:%s user=%s duration=%ss steps_ok=%d steps_err=%d message=%s',
        $status,
        $user['name'],
        $duration,
        $provisionStats['ok'],
        $provisionStats['err'],
        $message
    );
    logProvisionMessage($summary);
    pmssUserWriteLogs(
        pmssUserBaseContext(
            'add',
            'summary',
            $user['name'],
            array(
                'status'      => $status === 'SUCCESS' ? 'OK' : 'ERR',
                'result'      => $status,
                'message'     => $message,
                'duration'    => $duration,
                'exit_code'   => $exitCode,
                'steps_ok'    => $provisionStats['ok'],
                'steps_err'   => $provisionStats['err'],
                'last_error'  => $provisionStats['last_error'],
            )
        )
    );
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


//Create the user
$rc = runProvisionStep(
    'Create system user',
    sprintf('useradd --skel /etc/skel -m %s', escapeshellarg($user['name']))
);
if ($rc !== 0) {
    logProvisionMessage('FATAL: Create system user failed; aborting provisioning');
    finalizeProvision('FAIL', 'useradd_failed', 1);
    exit(1);
}
$pwEntry = null;
if (function_exists('posix_getpwnam')) {
    $pwEntry = @posix_getpwnam($user['name']);
}
if (!is_dir($homePath)) {
    logProvisionMessage('FATAL: Home directory missing after useradd; aborting provisioning');
    finalizeProvision('FAIL', 'home_missing', 1);
    exit(1);
}
if (is_array($pwEntry) && isset($pwEntry['uid'])) {
    $homeOwner = @fileowner($homePath);
    if ($homeOwner !== false && (int) $homeOwner !== (int) $pwEntry['uid']) {
        logProvisionMessage('FATAL: Home directory owner mismatch after useradd; aborting provisioning');
        finalizeProvision('FAIL', 'home_owner_mismatch', 1);
        exit(1);
    }
}
$safeChangePw = sprintf('/scripts/changePw.php %s [redacted]', escapeshellarg($user['name']));
$rc = runProvisionStep(
    'Set initial password',
    sprintf('/scripts/changePw.php %s %s', escapeshellarg($user['name']), escapeshellarg($user['password'])),
    $safeChangePw
);
if ($rc !== 0) {
    logProvisionMessage('FATAL: Initial password update failed; aborting provisioning');
    finalizeProvision('FAIL', 'password_failed', 1);
    exit(1);
}
runProvisionStep(
    'Unlock user account',
    sprintf('usermod -U %s', escapeshellarg($user['name']))
);
runProvisionStep(
    'Set expiry far in future',
    sprintf('usermod --expiredate 2100-01-01 %s', escapeshellarg($user['name']))
);
#passthru("usermod -G {$user['name']} www-data");

if (file_exists('/bin/bash')) { // Set shell
    runProvisionStep(
        'Ensure bash shell',
        sprintf('chsh -s /bin/bash %s', escapeshellarg($user['name']))
    );
}

// Record core attributes in the user config store before provisioning services.
	$userDb->addUser( $user['name'], array(
	    'ramMiB' => $user['memory'],
	    'quota' => $user['quota'],
	    'quotaBurst' => round(((float) $user['quota']) * 1.25),
	    'rtorrentPort' => 0,    #TODO Choose port here and use that for the userConfig :)
	    'billingId' => 0,
	    'trafficLimit' => 0,
	    'suspended' => false
	));

// Assign HTTP server port
runProvisionStep(
    'Assign lighttpd port',
    sprintf('/scripts/util/portManager.php assign %s lighttpd', escapeshellarg($user['name']))
);

// Configure quota, rtorrent and ruTorrent.
$rc = runProvisionStep(
    'Apply user configuration',
    sprintf('/scripts/util/userConfig.php %s %s %s',
        escapeshellarg($user['name']),
        escapeshellarg($user['memory']),
        escapeshellarg($user['quota'])
    )
);
if ($rc !== 0) {
    logProvisionMessage('FATAL: User configuration failed; aborting provisioning');
    finalizeProvision('FAIL', 'user_config_failed', 1);
    exit(1);
}

// Record per-user service ports assigned during configuration.
$portFiles = [
    'rclone' => $homePath.'/.rclonePort',
    'qbittorrent' => $homePath.'/.qbittorrentPort',
    'deluge' => $homePath.'/.delugePort',
];
foreach ($portFiles as $label => $path) {
    if (!is_file($path)) {
        continue;
    }
    $port = (int) trim((string) @file_get_contents($path));
    if ($port <= 0) {
        continue;
    }
    if ($label === 'deluge') {
        logProvisionMessage('Assigned deluge ports: scgi='.$port.' web='.($port + 1));
        continue;
    }
    logProvisionMessage('Assigned '.$label.' port: '.$port);
}

runProvisionStep(
    'Configure lighttpd vhost',
    sprintf('/scripts/util/userConfigLighttpd.php %s', escapeshellarg($user['name']))
);
runProvisionStep(
    'Regenerate nginx config',
    sprintf('/scripts/util/createNginxConfig.php --user %s', escapeshellarg($user['name']))
);


#passthru("/scripts/util/recreateLighttpdConfig.php");
#passthru('/etc/init.d/lighttpd force-reload');      // restart lighttpd




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
runProvisionStep(
    'Start rTorrent',
    sprintf('/scripts/startRtorrent %s', escapeshellarg($user['name']))
);
runProvisionStep(
    'Start lighttpd',
    sprintf('/scripts/startLighttpd %s', escapeshellarg($user['name']))
);
runProvisionStep('Restart nginx', 'systemctl restart nginx || /etc/init.d/nginx restart || true');
runProvisionStep('Refresh network rules', '/scripts/util/setupNetwork.php');

if (!empty($user['trafficLimit']) &&
    $user['trafficLimit'] > 0) {
    // Persist traffic caps for both automation (runtime) and the user directory.
    if (!file_exists("/etc/seedbox/runtime/trafficLimits")) mkdir("/etc/seedbox/runtime/trafficLimits");
    file_put_contents( "/etc/seedbox/runtime/trafficLimits/{$user['name']}", $user['trafficLimit'] );
    chmod( "/etc/seedbox/runtime/trafficLimits/{$user['name']}", 0600  );  // Restrict permissions to this file
    file_put_contents("/home/{$user['name']}/.trafficLimit", $user['trafficLimit']);
    chmod( "/home/{$user['name']}/.trafficLimit", 0664  );  // Restrict permissions to this file
    logProvisionMessage('Traffic limit set: ' . $user['trafficLimit']);
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


// Crontab for the user
logProvisionMessage('Adding crontab');
runProvisionStep(
    'Install default crontab',
    sprintf('crontab -u%s /etc/seedbox/config/user.crontab.default', escapeshellarg($user['name']))
);

// Setting file permissions
runProvisionStep(
    'Queue permissions fix',
    sprintf('nohup /scripts/util/userPermissions.php %s >> /dev/null 2>&1 &', escapeshellarg($user['name']))
);

// Seed per-user quota file by invoking the same refresher used by cron to avoid duplication.
// Then normalize permissions to 0640 to align with userPermissions policy.
runProvisionStep('Seed quota file', 'php /scripts/cron/updateQuotas.php');
runProvisionStep(
    'Normalize quota file permissions',
    sprintf('chmod 640 %s', escapeshellarg("/home/{$user['name']}/.quota"))
);

// Seed traffic files with zero values so first login does not show errors before cron populates them.
// Format mirrors scripts/lib/traffic/storage.php consumers (serialized array with raw/display/daily keys).
try {
    $zeroRaw = ['month'=>0.0,'week'=>0.0,'day'=>0.0,'hour'=>0.0,'15min'=>0.0];
    $zeroDisplay = ['month'=>'0MiB','week'=>'0MiB','day'=>'0MiB','hour'=>'0MiB','15min'=>'0MiB'];
    $zeroTraffic = ['raw'=>$zeroRaw,'display'=>$zeroDisplay,'daily'=>[]];
    $homeBase = "/home/{$user['name']}";
    $runtimeStatsDir = '/var/run/pmss/trafficStats';
    if (!is_dir($runtimeStatsDir)) @mkdir($runtimeStatsDir, 0755, true);
    // Home files
    @file_put_contents("$homeBase/.trafficData", serialize($zeroTraffic));
    @chown("$homeBase/.trafficData", 'root');
    @chgrp("$homeBase/.trafficData", $user['name']);
    @chmod("$homeBase/.trafficData", 0640);
    @file_put_contents("$homeBase/.trafficDataLocal", serialize($zeroTraffic));
    @chown("$homeBase/.trafficDataLocal", 'root');
    @chgrp("$homeBase/.trafficDataLocal", $user['name']);
    @chmod("$homeBase/.trafficDataLocal", 0640);
    // Runtime cache
    @file_put_contents("$runtimeStatsDir/{$user['name']}", serialize($zeroTraffic));
    @chown("$runtimeStatsDir/{$user['name']}", 'root');
    @chgrp("$runtimeStatsDir/{$user['name']}", 'root');
    @chmod("$runtimeStatsDir/{$user['name']}", 0600);
    logProvisionMessage('Seeded traffic files with zero values');
} catch (\Throwable $e) {
    logProvisionMessage('Seeding traffic files failed: '.$e->getMessage());
}

// Ensure .trafficLimit exists even when no limit is configured at creation time.
if (empty($user['trafficLimit'])) {
    @file_put_contents("/home/{$user['name']}/.trafficLimit", '0');
    @chmod("/home/{$user['name']}/.trafficLimit", 0664);
}

finalizeProvision('SUCCESS', 'completed', 0);
