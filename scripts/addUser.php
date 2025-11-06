#!/usr/bin/php
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
$usage = 'Usage: addUser.php USERNAME PASSWORD MAX_RTORRENT_MEMORY_IN_MB DISK_QUOTA_IN_GB [trafficLimitGB]';
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
$userDb = new users();

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
}

/**
 * Run a shell command and log whether it succeeded without aborting.
 *
 * @param string $description Operator-facing label describing the action.
 * @param string $command     Full shell command executed through runCommand().
 *
 * @return int Return code bubbled up from the child command.
 */
function runProvisionStep(string $description, string $command): int
{
    $result = runCommand($command, false, 'logProvisionMessage');
    if ($result !== 0) {
        logProvisionMessage($description . ' failed (rc=' . $result . ')');
    } else {
        logProvisionMessage($description . ' completed');
    }
    return $result;
}

// Get our server hostname, and do some cleanup just to be safe
$hostname = trim( file_get_contents('/etc/hostname') );
$hostname = str_replace(array("\n", "\r", "\t"), array('','',''), $hostname);


//Create the user
runProvisionStep(
    'Create system user',
    sprintf('useradd --skel /etc/skel -m %s', escapeshellarg($user['name']))
);
runProvisionStep(
    'Set initial password',
    sprintf('/scripts/changePw.php %s %s', escapeshellarg($user['name']), escapeshellarg($user['password']))
);
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

// Record core attributes in the runtime database before provisioning services.
// Then to DB :)
$userDb->addUser( $user['name'], array(
    'rtorrentRam' => $user['memory'],
    'quota' => $user['quota'],
    'quotaBurst' => round( $user['quota'] * 1.25 ),
    'rtorrentPort' => 0,    #TODO Choose port here and use that for the userConfig :)
    'suspended' => false
));

// Assign HTTP server port
runProvisionStep(
    'Assign lighttpd port',
    sprintf('/scripts/util/portManager.php assign %s lighttpd', escapeshellarg($user['name']))
);

// Configure quota, rtorrent and ruTorrent.
runProvisionStep(
    'Apply user configuration',
    sprintf('/scripts/util/userConfig.php %s %s %s',
        escapeshellarg($user['name']),
        escapeshellarg($user['memory']),
        escapeshellarg($user['quota'])
    )
);

runProvisionStep(
    'Configure lighttpd vhost',
    sprintf('/scripts/util/configureLighttpd.php %s', escapeshellarg($user['name']))
);
runProvisionStep('Regenerate nginx config', '/scripts/util/createNginxConfig.php');


#passthru("/scripts/util/recreateLighttpdConfig.php");
#passthru('/etc/init.d/lighttpd force-reload');      // restart lighttpd




$userHomedirPath = "/home/{$user['name']}";

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
    passthru("chown {$user['name']}.{$user['name']} {$retrackerConfigPath}");
    passthru("chown {$user['name']}.{$user['name']} {$retrackerConfigPath}/retrackers.dat");
    passthru("chown {$user['name']}.{$user['name']} /home/{$user['name']}/www/rutorrent/share/users/{$user['name']}");
    passthru("chown {$user['name']}.{$user['name']} /home/{$user['name']}/www/rutorrent/share/users/{$user['name']}/torrents");
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
