#!/usr/bin/php
<?php
#TODO Make this fork a process on BG!
$usage = "Usage: ./userConfig.php LOCAL_USERNAME [REMOTE_USERNAME] REMOTE_HOSTNAME\n";
$usage .= "If only 3 arguments, remote username is considered to be the same as local username\n";
$usage .= "If remote hostname does not contain single . it will get .pulsedmedia.com appended automatically\n";

if (empty($argv[1]) or
    empty($argv[2]) ) die('Need arguments' . $usage . "\n");


if ($argc == 3) {
  $args = array(
        'localUser'         => $argv[1],
        'remoteUser'        => $argv[1],
    //    'remotePassword'    => $pass1,
        'hostname'          => $argv[2]
    );
} elseif ($argc == 4) {
    $args = array(
        'localUser'         => $argv[1],
        'remoteUser'        => $argv[2],
    //    'remotePassword'    => $pass1,
        'hostname'          => $argv[3]
    );

}

// Ask for remote user password
echo 'Please type remote user password: ';
$pass1 = trim(fgets(STDIN));
echo 'Please re-type password: ';
$pass2 = trim(fgets(STDIN));
$pass1 = str_replace("\n", '', $pass1);
$pass2 = str_replace("\n", '', $pass2);
if ($pass1 !== $pass2) die("Password mismatch!\n");
$args['remotePassword'] = $pass1;


if (strpos($args['hostname'], '.') === false) {
 echo "No dot in hostname, appending .pulsedmedia\n";
 $args['hostname'] .= '.pulsedmedia.com';
}

if (!file_exists('/home/' . $args['localUser']) or !is_dir('/home/' . $args['localUser'])) die("Local user does not seem to exist!\n");

echo "Arguments: \n";
echo "\tLocal user: {$args['localUser']}\n";
echo "\tRemote user: {$args['remoteUser']}\n";
echo "\tRemote password: {$args['remotePassword']}\n";
echo "\tHostname: {$args['hostname']}\n";

/* OLD which does not include everything
$script = <<<EOF
#!/usr/bin/expect
spawn rsync -av -e "ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l {$args['remoteUser']}" {$args['remoteUser']}@{$args['hostname']}:{data,session,www/rutorrent/share/users/{$args['remoteUser']},.rtorrent.rc.custom,.lighttpd/custom,www/public,{$args['remoteUser']}} /home/{$args['localUser']}/
expect "s password:"
send "{$args['remotePassword']}\n";
interact

EOF;
*/


// Let's try migrate everything! :)

// We have to create rsync comomands into separate bash script if it has -- in options, this is being parsed by tcl as something otherwise.
// Esp important is to call #!/bin/bash properly, otherwise -- is parsed
$rsyncCommand = "#!/bin/bash\nrsync -av -e \"ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l {$args['remoteUser']}\" --exclude={.rtorrent.rc,.config/qBittorrent/qBittorrent.conf,.config/deluge/core.conf,.config/deluge/web.conf,.cache,www,session,www/rutorrent/share,.lighttpd,.logs,.local,.lighttpd.conf,.quota,.rtorrentExecuteRun,.trafficData,.trafficDataLocal,rTorrentLog,.bonusQuota,.billingId,.trafficLimit} {$args['remoteUser']}@{$args['hostname']}:. /home/{$args['localUser']}/";
$rsyncCommandFilename = "rsync-" . sha1( time() . rand(1, 50000000) . serialize($args) );

$script = <<<EOF
#!/usr/bin/expect
spawn /root/{$rsyncCommandFilename}
expect "s password:"
send "{$args['remotePassword']}\n";
interact

EOF;


$script2 = <<<EOF
#!/usr/bin/expect
spawn rsync -av -e "ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l {$args['remoteUser']}"  {$args['remoteUser']}@{$args['hostname']}:{session,www/rutorrent/share,.lighttpd/custom,.local,.lighttpd/custom.d,www/public} /home/{$args['localUser']}/
expect "s password:"
send "{$args['remotePassword']}\n";
interact

EOF;


// More than sufficiently random filename
$randomFilename = 'transfer-' . sha1( time() . rand(1, 50000000) . serialize($args) );
if (empty($randomFilename)) die("For a bizarre reason, could not create random filename for the script!\n");


file_put_contents("/root/{$randomFilename}", $script);
`chown root.root /root/{$randomFilename}`;
`chmod 700 /root/{$randomFilename}`;

file_put_contents("/root/{$randomFilename}2", $script2);
`chown root.root /root/{$randomFilename}2`;
`chmod 700 /root/{$randomFilename}2`;

file_put_contents("/root/{$rsyncCommandFilename}", $rsyncCommand);
`chown root.root /root/{$rsyncCommandFilename}`;
`chmod 700 /root/{$rsyncCommandFilename}`;

echo "Scripts created, executing:\n";
for($retries=0; $retries<=30; ++$retries) {
    passthru("/root/{$randomFilename};");
    sleep( round(rand(60, 360)) );
}

echo "Last step executing:\n";
for($retries=0; $retries<=2; ++$retries) {
    passthru("/root/{$randomFilename}2;");
    sleep( round(rand(60, 360)) );
}

unlink("/root/{$randomFilename}");
unlink("/root/{$randomFilename}2");
unlink("/root/{$rsyncCommandFilename}");



echo "Transfer done, setting up things\n";
if ($args['remoteUser'] !== $args['localUser']) {   // Rename ruTorrent users directory
    if (!file_exists("/home/{$args['localUser']}/www/rutorrent/share/users/{$args['localUser']}")) { // Check that the localUser dir doesn't exist yet
        `mv /home/{$args['localUser']}/www/rutorrent/share/users/{$args['remoteUser']} /home/{$args['localUser']}/www/rutorrent/share/users/{$args['localUser']}`;
    }
}
// Chown the migrated data
//$mask = '/*';
$mask = '';
`chown {$args['localUser']}.{$args['localUser']} /home/{$args['localUser']}{$mask} -R`;

echo "Requesting rTorrent restart\n";
touch("/home/{$args['localUser']}/www/.rtorrentRestart");
chown("/home/{$args['localUser']}/www/.rtorrentRestart", $args['localUser']);

echo "\n\t******* User's password was: {$args['remotePassword']}\n";

exit(0);
