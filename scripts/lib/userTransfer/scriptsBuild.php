<?php
/**
 * Script builders for user transfers (rsync and expect wrappers).
 *
 * @license GPL-3.0-only
 */

/**
 * Build the bash script that performs the main rsync pull (excluding volatile paths).
 */
function pmssUserTransferBuildRsyncMain(array $cfg): string
{
    $remoteUser = $cfg['remoteUser'];
    $hostname = $cfg['hostname'];
    $localUser = $cfg['localUser'];

    // Keep the exclude list in a stable order for readability and diffing.
    $excludes = [
        '.rtorrent.rc',
        '.config/qBittorrent/qBittorrent.conf',
        '.config/deluge/core.conf',
        '.config/deluge/web.conf',
        '.cache',
        'www',
        'session',
        'www/rutorrent/share',
        '.lighttpd',
        '.logs',
        '.local',
        '.lighttpd.conf',
        '.quota',
        '.rtorrentExecuteRun',
        '.trafficData',
        '.trafficDataLocal',
        'rTorrentLog',
        '.bonusQuota',
        '.bonusTraffic',
        '.billingId',
        '.trafficLimit',
    ];

    $excludeArgs = [];
    foreach ($excludes as $item) {
        $excludeArgs[] = '--exclude='.escapeshellarg($item);
    }

    $ssh = sprintf(
        'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l %s',
        escapeshellarg($remoteUser)
    );

    $cmd = 'rsync -av -e '.escapeshellarg($ssh)
        .' '.implode(' ', $excludeArgs)
        .' '.escapeshellarg($remoteUser.'@'.$hostname.':/home/'.$remoteUser.'/')
        .' '.escapeshellarg('/home/'.$localUser.'/');

    return "#!/bin/bash\nset -e\n{$cmd}\n";
}

/**
 * Build the bash script that pulls volatile paths after the main sync.
 */
function pmssUserTransferBuildRsyncFinal(array $cfg): string
{
    $remoteUser = $cfg['remoteUser'];
    $hostname = $cfg['hostname'];
    $localUser = $cfg['localUser'];

    // Keep this list explicit; do not rely on brace expansion inside expect.
    $sources = [
        '/home/'.$remoteUser.'/session',
        '/home/'.$remoteUser.'/www/rutorrent/share',
        '/home/'.$remoteUser.'/.lighttpd/custom',
        '/home/'.$remoteUser.'/.lighttpd/custom.d',
        '/home/'.$remoteUser.'/.local',
        '/home/'.$remoteUser.'/www/public',
    ];

    $ssh = sprintf(
        'ssh -o Compression=no -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -l %s',
        escapeshellarg($remoteUser)
    );

    $args = [];
    foreach ($sources as $source) {
        $args[] = escapeshellarg($remoteUser.'@'.$hostname.':'.$source);
    }

    $cmd = 'rsync -av -e '.escapeshellarg($ssh)
        .' '.implode(' ', $args)
        .' '.escapeshellarg('/home/'.$localUser.'/');

    return "#!/bin/bash\nset -e\n{$cmd}\n";
}

/**
 * Build a minimal Expect wrapper that injects the password via env and propagates exit codes.
 */
function pmssUserTransferBuildExpectWrapper(): string
{
    return <<<'EXP'
#!/usr/bin/expect -f
set timeout -1

if {[llength $argv] != 1} {
    puts stderr "Usage: transfer.expect <command-path>"
    exit 2
}

if {![info exists env(PMSS_USER_TRANSFER_PASSWORD)]} {
    puts stderr "Missing env PMSS_USER_TRANSFER_PASSWORD"
    exit 2
}

set password $env(PMSS_USER_TRANSFER_PASSWORD)
set cmd [lindex $argv 0]

spawn -noecho $cmd
expect {
    -re "(?i)assword:" {
        send -- "$password\r"
        exp_continue
    }
    eof
}

set result [wait]
exit [lindex $result 3]
EXP;
}
