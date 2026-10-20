<?php
/**
 * Remote command script builders for user transfers.
 * Owns generated shell/Expect payload shapes; execution lives elsewhere.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/dockerContainerIds.php';
require_once __DIR__.'/receiverNamespace.php';

function pmssUserTransferScratchPaths(string $scratchRoot): array
{
    $scratchRoot = rtrim($scratchRoot, '/');
    return [
        'expect' => $scratchRoot.'/transfer.expect', 'authProbe' => $scratchRoot.'/auth-probe.sh',
        'identityProbe' => $scratchRoot.'/identity-probe.sh', 'namespaceProbe' => $scratchRoot.'/namespace-probe.sh',
        'mainScript' => $scratchRoot.'/rsync-main.sh', 'finalScript' => $scratchRoot.'/rsync-final.sh',
        'remoteSizeScript' => $scratchRoot.'/remote-size.sh', 'qbittorrentProbeScript' => $scratchRoot.'/qbittorrent-categories.sh',
        'dockerQuiesceScript' => $scratchRoot.'/docker-quiesce.sh', 'dockerIds' => $scratchRoot.'/docker-ids',
        'dockerStartScript' => $scratchRoot.'/docker-start.sh',
        'sourceHoldScript' => $scratchRoot.'/eternal-hold.sh', 'sourceReleaseScript' => $scratchRoot.'/eternal-release.sh',
        'qbittorrentConfig' => $scratchRoot.'/qBittorrent.conf',
        'qbittorrentCategories' => $scratchRoot.'/categories.json',
    ];
}

function pmssUserTransferBuildSshCommand(string $remoteUser, array $extraOptions = []): string
{
    // The receiver runs as the customer; ignore customer-controlled SSH config
    // so it cannot redirect the password prompt to a ProxyCommand.
    return 'ssh -F /dev/null '.implode(' ', array_merge([
        '-o Compression=no', '-o UserKnownHostsFile=/dev/null', '-o StrictHostKeyChecking=no',
    ], $extraOptions, ['-l '.escapeshellarg($remoteUser)]));
}

/** Build a probe command for validated transfer config; callers own script headers and redirection. */
function pmssUserTransferBuildSshProbeCommand(array $cfg, string $remoteCommand): string
{
    return pmssUserTransferBuildSshCommand($cfg['remoteUser'], ['-o ConnectTimeout=20', '-o NumberOfPasswordPrompts=1'])
        .' '.escapeshellarg($cfg['hostname']).' '.escapeshellarg($remoteCommand);
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

    $namespace = !empty($cfg['namespaceRsync']);
    // One boundary flag retains empty mount directories for customer remounts.
    $rsync = 'rsync -aHAX --numeric-ids --stats --one-file-system'
        .($namespace ? ' --rsync-path='.escapeshellarg('unshare --map-auto --map-root-user rsync')
            : ' --usermap='.escapeshellarg($cfg['receiver']['sourceUid'].':0')
                .' --groupmap='.escapeshellarg($cfg['receiver']['sourceGid'].':0'))
        .' --filter='.escapeshellarg('-x user.rsync.*').' --filter='.escapeshellarg('-x! user.*')
        .($options === '' ? '' : ' '.$options).' -e '
        .escapeshellarg(pmssUserTransferBuildSshCommand($cfg['remoteUser']))
        .' '.implode(' ', $arguments).' '.escapeshellarg('/home/'.$cfg['localUser'].'/');
    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildReceiver($cfg, $rsync);
}

function pmssUserTransferBuildRsyncMain(array $cfg): string
{
    $excludes = [
        '.rtorrent.rc', '.config/qBittorrent/qBittorrent.conf', '.qbittorrentPort', '.config/pmss-user.json', '.config/deluge/core.conf',
        '.config/deluge/web.conf', '.cache', 'www', 'session', 'www/rutorrent/share',
        '.lighttpd', '.logs', '.local', '.lighttpd.conf', '.quota', '.rtorrentExecuteRun', '.trafficData',
        '.trafficDataLocal', '.trafficDataIngress', '.trafficDataIngressLocal', 'rTorrentLog', '.bonusQuota',
        '.bonusTraffic', '.trafficLimit', '.resourceData', '*.pmss-tmp-*',
        '/.billingId', '/.billingServiceId', '/.billingClientId',
        '/.mcxPikkumies/pikkumies/local',
    ];

    return pmssUserTransferBuildRsyncCommand($cfg, ['/home/'.$cfg['remoteUser'].'/'], $excludes);
}

function pmssUserTransferBuildRsyncFinal(array $cfg): string
{
    $remoteHome = '/home/'.$cfg['remoteUser'];
    return pmssUserTransferBuildRsyncCommand($cfg, [
        $remoteHome.'/./session', $remoteHome.'/./www/rutorrent/share', $remoteHome.'/./.lighttpd/custom',
        $remoteHome.'/./.lighttpd/custom.d', $remoteHome.'/./.local', $remoteHome.'/./www/public',
        $remoteHome.'/./.mcxPikkumies/pikkumies/local',
    ], ['/session/rtorrent.lock', '/.billingId', '/.billingServiceId', '/.billingClientId', '/.mcxPikkumies/pikkumies/local/eternals.hold'], ['-R', '--ignore-missing-args']);
}

/** Source commands run under the authenticated account, never through remote root. */
function pmssUserTransferBuildEternalSourceCommand(array $cfg, ?int $until): string
{
    $binary = '"$HOME/.mcxPikkumies/pikkumies/bin/pikkumies"';
    $marker = '"$HOME/.mcxPikkumies/pikkumies/local/eternals.hold"';
    $remote = $until === null
        ? 'rm -f -- '.$marker
        : 'if [ -x '.$binary.' ] && [ -f /etc/seedbox/config/pikkumiesEternals.enabled ]; then '
            .$binary.' eternal hold --until '.(string) $until.'; fi';
    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshProbeCommand($cfg, $remote)."\n";
}

function pmssUserTransferBuildAuthProbe(array $cfg): string
{
    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshProbeCommand($cfg, '/bin/true')."\n";
}

/** Probe the source as the authenticated account, with no account-controlled SSH config. */
function pmssUserTransferBuildNamespaceProbe(array $cfg): string
{
    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshProbeCommand(
        $cfg, 'unshare --map-auto --map-root-user true'
    )." >/dev/null 2>&1\n";
}

/** Both account-owned namespace probes must succeed before changing rsync's ID space. */
function pmssUserTransferNamespaceRsyncAvailable(int $localRc, int $remoteRc): bool
{
    return $localRc === 0 && $remoteRc === 0;
}

/** Capture the same bounded container set that is stopped on the source. */
function pmssUserTransferBuildDockerQuiesce(array $cfg, string $localIdsPath): string
{
    $remote = 'R=/run/user/$(id -u); S=$R/docker.sock; '
        .'[ -S "$S" ] && command -v docker >/dev/null 2>&1 || exit 0; '
        .'ids=$(DOCKER_HOST=unix://$S docker ps -q --no-trunc | head -n 500) || exit 0; '
        .'[ -n "$ids" ] || exit 0; printf "\n"; printf "%s\n" "$ids"; '
        .'DOCKER_HOST=unix://$S docker stop -t 30 $ids >/dev/null';

    return "#!/bin/bash\nset -e\numask 077\n"
        .pmssUserTransferBuildSshProbeCommand($cfg, $remote)
        .' | head -c 65536 > '.escapeshellarg($localIdsPath)."\n";
}

/** Restart only a caller-validated set on the source account's daemon. */
function pmssUserTransferBuildDockerStartScript(array $cfg, array $ids): string
{
    if ($ids === [] || count($ids) > 500) {
        throw new RuntimeException('Invalid Docker container ID set');
    }
    if (!pmssUserDockerContainerIdsValid($ids)) throw new RuntimeException('Invalid Docker container ID');
    $remote = 'DOCKER_HOST=unix:///run/user/$(id -u)/docker.sock docker start '
        .implode(' ', array_map('escapeshellarg', $ids)).' >/dev/null 2>&1';
    return "#!/bin/bash\nset -e\numask 077\n".pmssUserTransferBuildSshProbeCommand($cfg, $remote)."\n";
}

/** Keep unique, exact Docker IDs in source order and within the source cap. */
function pmssUserTransferValidDockerIds(string $raw, int $max = 500): array
{
    if ($max < 1) return [];
    $ids = [];
    foreach (explode("\n", $raw) as $line) {
        if (!pmssUserDockerContainerIdValid($line) || isset($ids[$line])) {
            continue;
        }
        $ids[$line] = true;
        if (count($ids) >= $max) {
            break;
        }
    }
    return array_keys($ids);
}

/** Rsync's routine live-source partial results can still contain the store. */
function pmssUserTransferDockerRcTransferred(int $rc): bool
{
    return in_array($rc, [0, 23, 24], true);
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
