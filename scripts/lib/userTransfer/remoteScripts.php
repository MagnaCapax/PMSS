<?php
/** Transfer payload facade and stable scratch layout. @license GPL-3.0-only */

require_once __DIR__.'/receiverNamespace.php';
require_once __DIR__.'/sshScripts.php';
require_once __DIR__.'/rsyncScripts.php';
require_once __DIR__.'/dockerScripts.php';

function pmssUserTransferScratchPaths(string $scratchRoot): array
{
    $scratchRoot = rtrim($scratchRoot, '/');
    return [
        'expect' => $scratchRoot.'/transfer.expect', 'authProbe' => $scratchRoot.'/auth-probe.sh',
        'identityProbe' => $scratchRoot.'/identity-probe.sh', 'namespaceProbe' => $scratchRoot.'/namespace-probe.sh',
        'newestProbe' => $scratchRoot.'/newest-probe.sh',
        'mainScript' => $scratchRoot.'/rsync-main.sh', 'finalScript' => $scratchRoot.'/rsync-final.sh',
        'remoteSizeScript' => $scratchRoot.'/remote-size.sh', 'qbittorrentProbeScript' => $scratchRoot.'/qbittorrent-categories.sh',
        'dockerQuiesceScript' => $scratchRoot.'/docker-quiesce.sh', 'dockerIds' => $scratchRoot.'/docker-ids',
        'dockerStartScript' => $scratchRoot.'/docker-start.sh',
        'sourceHoldScript' => $scratchRoot.'/eternal-hold.sh', 'sourceReleaseScript' => $scratchRoot.'/eternal-release.sh',
        'qbittorrentConfig' => $scratchRoot.'/qBittorrent.conf',
        'qbittorrentCategories' => $scratchRoot.'/categories.json',
    ];
}
