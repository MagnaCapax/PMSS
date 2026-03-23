<?php
/**
 * User transfer entrypoint.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/sessionRewrite.php';

/**
 * Build the SSH command shared by rsync wrappers and the auth probe.
 */
function pmssUserTransferBuildSshCommand(string $remoteUser, array $extraOptions = []): string
{
    return 'ssh '.implode(' ', array_merge([
        '-o Compression=no', '-o UserKnownHostsFile=/dev/null', '-o StrictHostKeyChecking=no',
    ], $extraOptions, ['-l '.escapeshellarg($remoteUser)]));
}

/**
 * Build a strict rsync wrapper script for the transfer flow.
 */
function pmssUserTransferBuildRsyncScript(array $cfg, array $arguments): string
{
    return "#!/bin/bash\nset -e\n".'rsync -av -e '.escapeshellarg(pmssUserTransferBuildSshCommand($cfg['remoteUser']))
        .' '.implode(' ', $arguments).' '.escapeshellarg('/home/'.$cfg['localUser'].'/')."\n";
}

/**
 * Build the bash script that performs the main rsync pull (excluding volatile paths).
 */
function pmssUserTransferBuildRsyncMain(array $cfg): string
{
    [$remoteUser, $hostname] = [$cfg['remoteUser'], $cfg['hostname']];

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
        '.trafficDataIngress',
        '.trafficDataIngressLocal',
        'rTorrentLog',
        '.bonusQuota',
        '.bonusTraffic',
        '.billingId',
        '.trafficLimit',
    ];

    return pmssUserTransferBuildRsyncScript(
        $cfg,
        array_merge(
            array_map(static function (string $item): string { return '--exclude='.escapeshellarg($item); }, $excludes),
            [escapeshellarg($remoteUser.'@'.$hostname.':/home/'.$remoteUser.'/')]
        )
    );
}

/**
 * Build the bash script that pulls volatile paths after the main sync.
 */
function pmssUserTransferBuildRsyncFinal(array $cfg): string
{
    [$remoteUser, $hostname] = [$cfg['remoteUser'], $cfg['hostname']];

    // Keep this list explicit; do not rely on brace expansion inside expect.
    $sources = [
        '/home/'.$remoteUser.'/session',
        '/home/'.$remoteUser.'/www/rutorrent/share',
        '/home/'.$remoteUser.'/.lighttpd/custom',
        '/home/'.$remoteUser.'/.lighttpd/custom.d',
        '/home/'.$remoteUser.'/.local',
        '/home/'.$remoteUser.'/www/public',
    ];

    return pmssUserTransferBuildRsyncScript(
        $cfg,
        array_map(static function (string $source) use ($remoteUser, $hostname): string { return escapeshellarg($remoteUser.'@'.$hostname.':'.$source); }, $sources)
    );
}

/**
 * Build a cheap SSH auth probe used to fail fast on bad credentials.
 */
function pmssUserTransferBuildAuthProbe(array $cfg): string
{
    [$remoteUser, $hostname] = [$cfg['remoteUser'], $cfg['hostname']];

    return "#!/bin/bash\nset -e\n".pmssUserTransferBuildSshCommand(
        $remoteUser,
        ['-o ConnectTimeout=20', '-o NumberOfPasswordPrompts=1']
    ).' '.escapeshellarg($hostname).' '.escapeshellarg('/bin/true')."\n";
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

/**
 * Entry point used by scripts/util/userTransfer.php.
 */
function pmssUserTransferMain(array $argv): int
{
    try {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            throw new RuntimeException('This script must be run as root', 1);
        }
        $cfg = pmssUserTransferParseCli($argv);
        $home = pmssUserTransferAssertSafeLocalHome($cfg['localUser']);

        if ($cfg['dryRun']) {
            putenv('PMSS_DRY_RUN=1');
        }

        // Disable command timeout for rsync operations — TB-scale transfers take hours.
        // See: GH #203 — userTransfer times out on large transfers
        putenv('PMSS_COMMAND_TIMEOUT=0');

        if ($cfg['suffixAppended']) {
            logMessage('[INFO] No dot in hostname, appending .pulsedmedia.com');
        }

        logMessage('[START] User transfer initialised');
        logMessage(sprintf('[INFO] Local user=%s Remote user=%s Host=%s', $cfg['localUser'], $cfg['remoteUser'], $cfg['hostname']));
        logMessage(sprintf('[INFO] Main passes=%d Final passes=%d Sleep=%d..%d', $cfg['mainPasses'], $cfg['finalPasses'], $cfg['sleepMin'], $cfg['sleepMax']));

        // Dry runs should be fully non-interactive and avoid writing temp scripts.
        if ($cfg['dryRun']) {
            $scratch = '/root/pmss-userTransfer-<generated>';
            $expect = $scratch.'/transfer.expect';
            $authProbe = $scratch.'/auth-probe.sh';
            $mainScript = $scratch.'/rsync-main.sh';
            $finalScript = $scratch.'/rsync-final.sh';
            runStep('Validating remote SSH authentication', pmssBuildCommand($expect, [$authProbe]));
            runStep('Pulling home data (pass 1/'.$cfg['mainPasses'].')', pmssBuildCommand($expect, [$mainScript]));
            runStep('Pulling volatile data (pass 1/'.$cfg['finalPasses'].')', pmssBuildCommand($expect, [$finalScript]));
            runStep('Normalising user permissions', pmssBuildCommand('php', [dirname(__DIR__).'/../util/userPermissions.php', $cfg['localUser']]));
            logMessage('[SKIP] Dry run complete');
            return 0;
        }

        $fromEnv = getenv('PMSS_USER_TRANSFER_PASSWORD');
        if ($fromEnv !== false && $fromEnv !== '') {
            $password = $fromEnv;
        } else {
            $isTty = function_exists('posix_isatty') && posix_isatty(STDIN);
            if (!$isTty) {
                throw new RuntimeException('Password missing (set PMSS_USER_TRANSFER_PASSWORD for non-interactive runs)', 1);
            }

            // Avoid echoing the password on the console.
            $mode = trim((string) @shell_exec('stty -g 2>/dev/null'));
            $pass1 = '';
            $pass2 = '';
            try {
                @shell_exec('stty -echo 2>/dev/null');
                echo 'Remote user password: ';
                $pass1 = (string) fgets(STDIN);
                echo PHP_EOL.'Re-type password: ';
                $pass2 = (string) fgets(STDIN);
                echo PHP_EOL;
            } finally {
                if ($mode !== '') {
                    @shell_exec('stty '.escapeshellarg($mode).' 2>/dev/null');
                } else {
                    @shell_exec('stty echo 2>/dev/null');
                }
            }

            $pass1 = trim($pass1);
            $pass2 = trim($pass2);
            if ($pass1 === '' || $pass1 !== $pass2) {
                throw new RuntimeException('Password mismatch', 1);
            }
            $password = $pass1;
        }
        putenv('PMSS_USER_TRANSFER_PASSWORD='.$password);

        try {
            $token = bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            $token = sha1(microtime(true).'-'.mt_rand());
        }
        $scratch = '/root/pmss-userTransfer-'.$token;
        if (!@mkdir($scratch, 0700, true) && !is_dir($scratch)) {
            throw new RuntimeException('Failed to create scratch directory: '.$scratch, 1);
        }
        @chmod($scratch, 0700);

        $paths = [];
        $cleanup = function () use (&$paths, $scratch): void {
            foreach ($paths as $p) {
                if ($p !== '' && file_exists($p)) {
                    @unlink($p);
                }
            }
            if (is_dir($scratch)) {
                @rmdir($scratch);
            }
        };
        register_shutdown_function($cleanup);

        // Generate scripts under /root with restrictive permissions.
        $expect = $scratch.'/transfer.expect';
        $authProbe = $scratch.'/auth-probe.sh';
        $mainScript = $scratch.'/rsync-main.sh';
        $finalScript = $scratch.'/rsync-final.sh';

        $paths = [$expect, $authProbe, $mainScript, $finalScript];
        pmssUserTransferWriteFile($expect, pmssUserTransferBuildExpectWrapper()."\n", 0700);
        pmssUserTransferWriteFile($authProbe, pmssUserTransferBuildAuthProbe($cfg), 0700);
        pmssUserTransferWriteFile($mainScript, pmssUserTransferBuildRsyncMain($cfg), 0700);
        pmssUserTransferWriteFile($finalScript, pmssUserTransferBuildRsyncFinal($cfg), 0700);

        // Validate credentials first so a bad password does not burn dozens of rsync passes.
        $authRc = runStep('Validating remote SSH authentication', pmssBuildCommand($expect, [$authProbe]));
        if ($authRc !== 0) {
            logMessage(sprintf('[ERR] Aborting user transfer: remote authentication pre-flight failed (rc=%d)', $authRc));
            $cleanup();
            putenv('PMSS_USER_TRANSFER_PASSWORD');
            return 1;
        }

        // Run repeated passes to converge the remote state before the final sync.
        $lastMainRc = 0;
        for ($i = 1; $i <= $cfg['mainPasses']; $i++) {
            $lastMainRc = runStep(
                sprintf('Pulling home data (pass %d/%d)', $i, $cfg['mainPasses']),
                pmssBuildCommand($expect, [$mainScript])
            );
            if ($i < $cfg['mainPasses']) {
                pmssUserTransferSleep($cfg['sleepMin'], $cfg['sleepMax'], 'Waiting before next main pass');
            }
        }

        // Final sync for volatile paths such as session data.
        $lastFinalRc = 0;
        for ($i = 1; $i <= $cfg['finalPasses']; $i++) {
            $lastFinalRc = runStep(
                sprintf('Pulling volatile data (pass %d/%d)', $i, $cfg['finalPasses']),
                pmssBuildCommand($expect, [$finalScript])
            );
            if ($i < $cfg['finalPasses']) {
                pmssUserTransferSleep($cfg['sleepMin'], $cfg['sleepMax'], 'Waiting before next final pass');
            }
        }

        pmssUserTransferPostSetup($cfg, $home);
        $cleanup();
        putenv('PMSS_USER_TRANSFER_PASSWORD');

        if ($cfg['printPassword']) {
            logMessage('[WARN] Remote password: '.$password);
        }

        if ($lastMainRc !== 0 || $lastFinalRc !== 0) {
            logMessage(sprintf('[WARN] User transfer finished with errors (main rc=%d, final rc=%d)', $lastMainRc, $lastFinalRc));
            return 1;
        }
        logMessage('[OK] User transfer complete');
        return 0;
    } catch (RuntimeException $e) {
        $code = $e->getCode();
        if ($code === 0) {
            echo $e->getMessage();
            return 0;
        }
        fwrite(STDERR, $e->getMessage().PHP_EOL);
        return is_int($code) && $code > 0 ? $code : 1;
    }
}

/**
 * Apply post-transfer steps (rename ruTorrent user dir, normalise permissions, restart marker).
 */
function pmssUserTransferPostSetup(array $cfg, string $home): void
{
    $localUser = $cfg['localUser'];
    $remoteUser = $cfg['remoteUser'];

    // Rename ruTorrent user directory when remote/local user differs.
    if ($remoteUser !== $localUser) {
        $src = $home.'/www/rutorrent/share/users/'.$remoteUser;
        $dst = $home.'/www/rutorrent/share/users/'.$localUser;
        if (file_exists($src) && !file_exists($dst)) {
            if (pmssUserTransferIsPathWithinHome($src, $home) && pmssUserTransferIsPathWithinHome(dirname($dst), $home)) {
                if (!@rename($src, $dst)) {
                    runStep(
                        'Renaming ruTorrent user directory',
                        pmssBuildCommand('mv', [$src, $dst])
                    );
                } else {
                    logMessage('[OK] Renamed ruTorrent user directory');
                }
            } else {
                logMessage('[WARN] Skipping ruTorrent rename (path escapes home)');
            }
        }
    }

    // Keep migrated rTorrent sessions usable when usernames differ between
    // source and destination accounts.
    pmssUserTransferRewriteRtorrentSessionPaths($cfg, $home);

    // Normalise ownership/permissions via the shared helper, which avoids unsafe
    // recursive chown dereferencing symlinks into the host filesystem.
    runStep(
        'Normalising user permissions',
        pmssBuildCommand('php', [dirname(__DIR__).'/../util/userPermissions.php', $localUser])
    );

    // Request rTorrent restart (best effort) so migrated data is picked up.
    $wwwDir = $home.'/www';
    if (is_dir($wwwDir) && !is_link($wwwDir) && pmssUserTransferIsPathWithinHome($wwwDir, $home)) {
        $marker = $wwwDir.'/.rtorrentRestart';
        runStep('Requesting rTorrent restart marker', pmssBuildCommand('touch', [$marker]));
        runStep('Setting rTorrent restart marker owner', pmssBuildCommand('chown', [$localUser.':'.$localUser, $marker]));
    } else {
        logMessage('[WARN] Skipping rTorrent restart marker (www dir missing or unsafe)');
    }
}
