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

/**
 * Preflight: libtorrent's ./configure hard-requires pkg-config libcrypto (from
 * libssl-dev). On Debian 11->12 dist-upgraded hosts where libssl3/openssl were
 * held during the openssh-removal-cascade mitigation, libssl-dev cannot install
 * and libcrypto.pc is absent — which silently broke the rebuild fleet-wide (GH #662).
 */
function pmssRtorrentBuildPrereqsPresent(): bool
{
    $out = [];
    $rc = 1;
    @exec('pkg-config --exists libcrypto 2>/dev/null', $out, $rc);
    return $rc === 0;
}

/** Verify the freshly built rtorrent binary actually loads its libraries and runs. */
function pmssRtorrentBinaryRuns(): bool
{
    if (!is_file('/usr/local/bin/rtorrent')) {
        return false;
    }

    return pmssAppVersionProbeSucceeded('/usr/local/bin/rtorrent -h >/dev/null 2>&1');
}

/** Persist a queryable marker so a silent build failure becomes fleet-detectable. */
function pmssRtorrentMarkBuildFailure(string $reason): void
{
    @file_put_contents('/var/log/pmss/rtorrent-build-failed', date('c').' '.$reason."\n", FILE_APPEND);
}

/** Clear the build-failure marker once a verified-good binary is in place. */
function pmssRtorrentClearBuildFailure(): void
{
    if (is_file('/var/log/pmss/rtorrent-build-failed')) {
        @unlink('/var/log/pmss/rtorrent-build-failed');
    }
}

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

    // Ensure build/runtime dependencies exist BEFORE any destructive cleanup.
    // #TODO migrate to shared package helper. (GH #132)
    runCommand(aptCmd('install -y libudns0 libudns-dev libcppunit-dev'));
    // libssl-dev provides libcrypto.pc, which libtorrent's ./configure requires. On Debian 12
    // PMSS DELIBERATELY holds libssl3/openssl at a PECL-ssh2-compatible version
    // (pmssHoldLibssl3ForPeclSsh2Compat, #436/#585), so the default libssl-dev candidate (a
    // newer point release) conflicts. Install libssl-dev MATCHED to the installed libssl3 so it
    // satisfies the hold instead of fighting it (the matched -dev is in bookworm-updates); this
    // needs NO hold release and does NOT disturb openssh. Fall back to unversioned where libssl3
    // is absent/unpinned (legacy Debian / fresh installs).
    $libssl3Ver = trim((string) @shell_exec('dpkg-query -W -f='.escapeshellarg('${Version}').' libssl3 2>/dev/null'));
    if ($libssl3Ver !== '' && preg_match('/^[0-9][A-Za-z0-9.+:~-]*$/', $libssl3Ver) === 1) {
        runCommand(aptCmd('install -y libssl-dev='.$libssl3Ver));
    }
    if (!pmssRtorrentBuildPrereqsPresent()) {
        runCommand(aptCmd('install -y libssl-dev')); // unpinned/legacy fallback
    }

    // Preflight: if libcrypto is STILL absent (version-matched libssl-dev not in the repo), ABORT
    // loudly and NON-destructively instead of wiping the existing libtorrent libs and leaving
    // rtorrent unrunnable. This is the GH #662 silent-fleet-breakage guard: a failed prereq must
    // not destroy state. Do NOT release the libssl3 hold — it is deliberate (PECL ssh2 #436/#585).
    if (!pmssRtorrentBuildPrereqsPresent()) {
        $log('[ERR] rtorrent rebuild BLOCKED: pkg-config libcrypto missing — version-matched '
            .'libssl-dev (=installed libssl3 '.$libssl3Ver.') is unavailable in the repo. Not wiping existing '
            .'libtorrent libraries; rtorrent left as-is. Do NOT release the PECL-ssh2 libssl3 hold; instead make '
            .'the matching libssl-dev available, then re-run update. See GH #662.');
        pmssRtorrentMarkBuildFailure('libcrypto-prereq-missing');
        return;
    }

    runStep('Cleaning legacy libtorrent libraries', 'rm -rf /usr/local/lib/libtorrent*; ldconfig;'); // Clean up old libtorrent installed files

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
    

    // Post-build verification. A failed build previously completed update.php "successfully"
    // (runStep does not abort the run) and left rtorrent crash-looping fleet-wide (GH #662).
    // Confirm the freshly built binary loads its libraries and runs; surface failure loudly.
    if (!pmssRtorrentBinaryRuns()) {
        $log('[ERR] rtorrent rebuild FAILED verification: /usr/local/bin/rtorrent does not load/run after build '
            .'(check `ldd /usr/local/bin/rtorrent` for missing libtorrent.so/libcppunit). rTorrent is DOWN on this host. See GH #662.');
        pmssRtorrentMarkBuildFailure('post-build-verify-failed');
    } else {
        pmssRtorrentClearBuildFailure();
        echo "*** Update done - rtorrent instances will restart within minute\n";
    }
}
