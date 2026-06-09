<?php
/**
 * rTorrent / libtorrent build helper.
 *
 * - Compiles xmlrpc-c (rev 3116) if the static libs are missing.
 * - Fetches the pre-packaged rTorrent and libtorrent tarballs from Pulsed Media
 *   mirrors and rebuilds them with udns + posix-fallocate optimisations when the
 *   running binary version differs from the expected target.
 * - Reloads templates and restarts user instances once the binaries are updated.
 *
 * This bootstrap has been battle-tested in production deployments since 2010
 * and should remain unchanged unless absolutely necessary. Coordinate with the
 * platform team before modifying the flow.
 *
 * @author  Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * #TODO Replace HTTP downloads and ad-hoc compiles with a reproducible,
 *       package-based approach (dpkg baselines or managed repository). (GH #132)
 * #TODO Refactor to use runStep wrappers and verify downloads via checksums. (GH #132)
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/remoteBinary.php';
require_once __DIR__.'/../distro.php';
require_once dirname(__DIR__, 2).'/rtorrent/legacyDirectives.php';

/**
 * Resolve rtorrent/libtorrent targets by detected Debian major version.
 *
 * Debian 10+ requires the 0.9.8/0.13.8 udns builds while legacy Debian
 * 8/9 keeps the historic 0.9.6/0.13.6 fallback.
 */
function pmssRtorrentResolveTargetVersions(array $distroInfo, string $legacyDebianVersion = ''): array
{
    $majorVersion = isset($distroInfo['version']) ? (int) $distroInfo['version'] : 0;
    if ($majorVersion <= 0 && preg_match('/^\s*([0-9]+)/', $legacyDebianVersion, $matches)) {
        $majorVersion = (int) $matches[1];
    }

    if ($majorVersion >= 10) {
        return [
            'rtorrent'   => '0.9.8-udns',
            'libtorrent' => '0.13.8-udns',
        ];
    }

    return [
        'rtorrent'   => '0.9.6',
        'libtorrent' => '0.13.6',
    ];
}

if (pmssEnvFlagEnabled('PMSS_RTORRENT_NO_ENTRYPOINT')) {
    return;
}

$log = 'logmsg';

$rtorrentVersion = pmssAppVersionProbeOutput('rtorrent -h');
// Resolve the target branch from distro detection instead of string-prefix
// checks on /etc/debian_version so codename/major overrides stay consistent.
$distroInfo = pmssDetectDistro();
$debianVersion = (string) @file_get_contents('/etc/debian_version');
$targets = pmssRtorrentResolveTargetVersions($distroInfo, $debianVersion);
$rtorrentVersionTarget = $targets['rtorrent'];
$rtorrentVersionTargetLib = $targets['libtorrent'];
$rtorrentCompileOptions = '--with-xmlrpc-c --disable-debug';
$rtorrentCompileOptionsLib = '--with-udns --with-posix-fallocate --disable-debug';
$xmlrpcVersion = '3116';

$checksums = [
    'rtorrent-0.9.8-udns.tar.gz' => '58a5d96c97c858736cf6dab2eec606c080bd23c0ab22864aecaac6d59233eefb',
    'libtorrent-0.13.8-udns.tar.gz' => 'eac93f60a9dd5cd0b010b3f4c6f3b08878c050feb4d57fb847c584c94cea4708',
    'rtorrent-0.9.6.tar.gz' => '1e69c24f1f26f8f07d58d673480dc392bfc4317818c1115265b08a7813ff5b0e',
    'libtorrent-0.13.6.tar.gz' => '2838a08c96edfd936aff8fbf99ecbb930c2bfca3337dd1482eb5fccdb80d5a04',
];

if (strpos($rtorrentVersion, "version {$rtorrentVersionTarget}.") === false) {  // Yeah i know kinda stupid place to look if we got the latest but ...
    echo "*** Updating rTorrent\n";
    
    runStep('Cleaning legacy libtorrent libraries', 'rm -rf /usr/local/lib/libtorrent*; ldconfig;'); // Clean up old libtorrent installed files
    
    // Ensure build/runtime dependencies exist. #TODO migrate to shared package helper. (GH #132)
    runCommand(aptCmd('install -y libudns0 libudns-dev libcppunit-dev'));

    echo "**** Remove old rtorrent packages\n";
    //passthru('rm -rf /tmp/rtorrent*; rm -rf /tmp/libtorrent*; rm -rf /tmp/xmlrpc*');
    runStep('Cleaning previous rtorrent sources', 'rm -rf /tmp/rtorrent* /tmp/libtorrent*');	// Not updating xmlrpc this time
    

    if (!file_exists('/usr/local/lib/libxmlrpc_client.a')) {
        echo "**** Updating xmlrpc-c to rev 3116\n";
        #passthru('cd /tmp; svn checkout https://svn.code.sf.net/p/xmlrpc-c/code/advanced xmlrpc-c -r 2776');
        runStep(
            "Checking out xmlrpc-c rev {$xmlrpcVersion}",
            pmssBuildCommand('svn', ['checkout', 'https://svn.code.sf.net/p/xmlrpc-c/code/advanced', 'xmlrpc-c', '-r', $xmlrpcVersion])
        );
        runStep('Building xmlrpc-c', 'cd /tmp/xmlrpc-c; ./configure; make -j12; make install; ldconfig; cd -');
    }

    
    
    echo "**** get new packages\n";
    // Source tarballs stay pinned to HTTPS URLs with SHA256 verification.
    $rtorrentTarball = "rtorrent-{$rtorrentVersionTarget}.tar.gz";
    $libtorrentTarball = "libtorrent-{$rtorrentVersionTargetLib}.tar.gz";
    $rtorrentSha = isset($checksums[$rtorrentTarball]) ? $checksums[$rtorrentTarball] : '';
    $libtorrentSha = isset($checksums[$libtorrentTarball]) ? $checksums[$libtorrentTarball] : '';

    if ($rtorrentSha === '' || $libtorrentSha === '') {
        $log('[WARN] Missing checksum for rtorrent/libtorrent tarballs; aborting');
        return;
    }

    $rtorrentUrl = "https://pulsedmedia.com/remote/pkg/{$rtorrentTarball}";
    $libtorrentUrl = "https://pulsedmedia.com/remote/pkg/{$libtorrentTarball}";
    echo "**** uncompressing ...\n";
    if (!pmssRunPinnedRemoteArchiveStep("rtorrent {$rtorrentVersionTarget} source", $rtorrentUrl, $rtorrentSha, $rtorrentTarball, "rtorrent-{$rtorrentVersionTarget}", 'Extracting rtorrent source', [], '/tmp')
        || !pmssRunPinnedRemoteArchiveStep("libtorrent {$rtorrentVersionTargetLib} source", $libtorrentUrl, $libtorrentSha, $libtorrentTarball, "libtorrent-{$rtorrentVersionTargetLib}", 'Extracting libtorrent source', [], '/tmp')) {
        return;
    }
    
    echo "**** compiling ....\n";
    echo "***** libtorrent\n";
    runStep(
        'Compiling libtorrent',
        "cd /tmp/libtorrent-{$rtorrentVersionTargetLib}; rm -f scripts/{libtool,lt*}.m4; ./autogen.sh; ./autogen.sh; ./configure {$rtorrentCompileOptionsLib}; make -j12; make install; ldconfig"
    );
    
    echo "***** rtorrent\n";
    runStep(
        'Compiling rtorrent',
        "cd /tmp/rtorrent-{$rtorrentVersionTarget}; rm -f scripts/{libtool,lt*}.m4; make clean; ./autogen.sh; ./autogen.sh; ./configure {$rtorrentCompileOptions}; make -j12; make install;"
    );
    
    echo "**** Killing all running rtorrent processes\n";
    # So many because of potentially updating from ancient version, who knows ... Who even knows if you try to update deb 5 machine what happens :P
    runStep('Killing rtorrent processes', 'killall -9 rtorrent');
    runStep('Killing rtorrent main processes', 'killall -9 "rtorrent main"');
    runStep('Killing rtorrent binary processes', 'killall -9 /usr/local/bin/rtorrent');


    if (file_exists('/etc/seedbox/config/template.rtorrent.rc')) {
        echo "**** Updating local .rtorrent.rc template\n";
        $localRtorrentRcTemplate = file_get_contents('/etc/seedbox/config/template.rtorrent.rc');
        if (!is_string($localRtorrentRcTemplate) || $localRtorrentRcTemplate === '') {
            echo "**** Skipping template update (failed to read template)\n";
        } else {
            file_put_contents(
                '/etc/seedbox/config/template.rtorrent.rc',
                pmssRtorrentNormalizeLegacyTemplate($localRtorrentRcTemplate)
            );
        }
    }
    

    echo "*** Update done - rtorrent instances will restart within minute\n";
}
