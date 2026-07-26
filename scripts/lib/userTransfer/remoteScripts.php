<?php
/**
 * Remote command script builders for user transfers.
 * Owns generated shell/Expect payload shapes; execution lives elsewhere.
 *
 * @license GPL-3.0-only
 */

function pmssUserTransferScratchPaths(string $scratchRoot): array
{
    $scratchRoot = rtrim($scratchRoot, '/');
    return [
        'expect' => $scratchRoot.'/transfer.expect', 'authProbe' => $scratchRoot.'/auth-probe.sh',
        'mainScript' => $scratchRoot.'/rsync-main.sh', 'finalScript' => $scratchRoot.'/rsync-final.sh',
        'remoteSizeScript' => $scratchRoot.'/remote-size.sh', 'qbittorrentProbeScript' => $scratchRoot.'/qbittorrent-categories.sh',
        'qbittorrentConfig' => $scratchRoot.'/qBittorrent.conf',
        'qbittorrentCategories' => $scratchRoot.'/categories.json',
    ];
}

function pmssUserTransferBuildSshCommand(string $remoteUser, array $extraOptions = []): string
{
    return 'ssh '.implode(' ', array_merge([
        '-o Compression=no', '-o UserKnownHostsFile=/dev/null', '-o StrictHostKeyChecking=no',
    ], $extraOptions, ['-l '.escapeshellarg($remoteUser)]));
}

function pmssUserTransferBuildRsyncCommand(
    array $cfg,
    array $sources,
    array $excludes = [],
    array $rsyncOptions = []
): string
{
    $options = implode(' ', array_map(static function (string $option): string {
        return escapeshellarg($option);
    }, $rsyncOptions));
    $arguments = array_map(static function (string $item): string {
        return '--exclude='.escapeshellarg($item);
    }, $excludes);
    $prefix = $cfg['remoteUser'].'@'.$cfg['hostname'].':';
    foreach ($sources as $source) {
        $arguments[] = escapeshellarg($prefix.$source);
    }

    return "#!/bin/bash\nset -e\n".'rsync -a --stats'.($options === '' ? '' : ' '.$options).' -e '
        .escapeshellarg(pmssUserTransferBuildSshCommand($cfg['remoteUser']))
        .' '.implode(' ', $arguments).' '.escapeshellarg('/home/'.$cfg['localUser'].'/')."\n";
}

function pmssUserTransferBuildRsyncMain(array $cfg): string
{
    $excludes = [
        '.rtorrent.rc', '.config/qBittorrent/qBittorrent.conf', '.qbittorrentPort', '.config/pmss-user.json', '.config/deluge/core.conf',
        '.config/deluge/web.conf', '.cache', 'www', 'session', 'www/rutorrent/share',
        '.lighttpd', '.logs', '.local', '.lighttpd.conf', '.quota', '.rtorrentExecuteRun', '.trafficData',
        '.trafficDataLocal', '.trafficDataIngress', '.trafficDataIngressLocal', 'rTorrentLog', '.bonusQuota',
        '.bonusTraffic', '.trafficLimit',
    ];

    return pmssUserTransferBuildRsyncCommand($cfg, ['/home/'.$cfg['remoteUser'].'/'], $excludes);
}

function pmssUserTransferBuildRsyncFinal(array $cfg): string
{
    $remoteHome = '/home/'.$cfg['remoteUser'];
    return pmssUserTransferBuildRsyncCommand($cfg, [
        $remoteHome.'/./session', $remoteHome.'/./www/rutorrent/share', $remoteHome.'/./.lighttpd/custom',
        $remoteHome.'/./.lighttpd/custom.d', $remoteHome.'/./.local', $remoteHome.'/./www/public',
    ], [], ['-R']);
}

function pmssUserTransferBuildAuthProbe(array $cfg): string
{
    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshCommand(
        $cfg['remoteUser'],
        ['-o ConnectTimeout=20', '-o NumberOfPasswordPrompts=1']
    ).' '.escapeshellarg($cfg['hostname']).' '.escapeshellarg('/bin/true')."\n";
}

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
