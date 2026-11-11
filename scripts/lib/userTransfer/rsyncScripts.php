<?php
/** Rsync pass selection and account receiver payloads. @license GPL-3.0-only */

function pmssUserTransferBuildRsyncCommand(
    array $cfg,
    array $sources,
    array $excludes = [],
    array $rsyncOptions = []
): string
{
    $destination = '/home/'.$cfg['localUser'].'/';
    if (isset($cfg['bwlimit'])) {
        // rsync forwards the cap to the remote sender; on cgroup v1 nothing else bounds buffered target writes.
        $rsyncOptions[] = '--bwlimit='.(int) $cfg['bwlimit'];
    }
    if (isset($cfg['into'])) {
        $destination .= pmssUserTransferValidateInto($cfg['into']).'/';
        $rsyncOptions[] = '--ignore-existing';
        // Unchanged files the live home already holds are not copied twice. The path is absolute on purpose: with a
        // relative '..' a delta transfer uses the differing live file as its basis and rsync discards the update
        // ("failed verification", rc 23), while a local whole-file copy hides it.
        $rsyncOptions[] = '--compare-dest=/home/'.$cfg['localUser'].'/';
    }
    $options = pmssCommandArgvShellQuote(array_map(static function (string $option): string { return $option; }, $rsyncOptions));
    $arguments = array_map(static function (string $item): string {
        return '--exclude='.escapeshellarg($item);
    }, $excludes);
    $prefix = $cfg['remoteUser'].'@'.$cfg['hostname'].':';
    foreach ($sources as $source) {
        $arguments[] = escapeshellarg($prefix.$source);
    }

    // One boundary flag retains empty mount directories for customer remounts.
    $rsync = 'rsync -aHAX --numeric-ids --stats --one-file-system'
        .(!empty($cfg['namespaceRsync']) ? ' --rsync-path='.escapeshellarg('unshare --map-auto --map-root-user rsync')
            : ' --usermap='.escapeshellarg($cfg['receiver']['sourceUid'].':0')
                .' --groupmap='.escapeshellarg($cfg['receiver']['sourceGid'].':0'))
        .' --filter='.escapeshellarg('-x user.rsync.*').' --filter='.escapeshellarg('-x! user.*')
        .($options === '' ? '' : ' '.$options).' -e '
        .escapeshellarg(pmssUserTransferBuildSshCommand($cfg['remoteUser']))
        .' '.implode(' ', $arguments).' '.escapeshellarg($destination);
    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildReceiver($cfg, $rsync);
}

function pmssUserTransferBuildRsyncMain(array $cfg): string
{
    // Home-level state must not hide customer data with the same names deeper in the tree.
    return pmssUserTransferBuildRsyncCommand($cfg, ['/home/'.$cfg['remoteUser'].'/'], [
        '/.rtorrent.rc', '/.config/qBittorrent/qBittorrent.conf', '/.qbittorrentPort', '/.config/pmss-user.json', '/.config/deluge/core.conf',
        '/.config/deluge/web.conf', '/.cache', '/www', '/session', '/www/rutorrent/share',
        '/.lighttpd', '/.logs', '/.local', '/.lighttpd.conf', '/.quota', '/.rtorrentExecuteRun', '/.trafficData',
        '/.trafficDataLocal', '/.trafficDataIngress', '/.trafficDataIngressLocal', '/rTorrentLog', '/.bonusQuota',
        '/.bonusTraffic', '/.trafficLimit', '/.resourceData', '*.pmss-tmp-*',
        '/.billingId', '/.billingServiceId', '/.billingClientId',
        '/.mcxPikkumies/pikkumies/local',
        '/.hermes',
    ]);
}

function pmssUserTransferBuildRsyncFinal(array $cfg): string
{
    $remoteHome = '/home/'.$cfg['remoteUser'];
    // qBittorrent keeps its target config and port, so retain its matching proxy too.
    return pmssUserTransferBuildRsyncCommand($cfg, [
        $remoteHome.'/./session', $remoteHome.'/./www/rutorrent/share', $remoteHome.'/./.lighttpd/custom',
        $remoteHome.'/./.lighttpd/custom.d', $remoteHome.'/./.local', $remoteHome.'/./www/public',
        $remoteHome.'/./.mcxPikkumies/pikkumies/local',
        $remoteHome.'/./.hermes',
    ], ['/session/rtorrent.lock', '/.lighttpd/custom.d/pmss-qbittorrent.conf', '/.billingId', '/.billingServiceId', '/.billingClientId', '/.mcxPikkumies/pikkumies/local/eternals.hold'], ['-R', '--ignore-missing-args']);
}
