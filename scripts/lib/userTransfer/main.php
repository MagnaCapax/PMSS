<?php
/**
 * User transfer entrypoint.
 *
 * @license GPL-3.0-only
 */

/**
 * Entry point used by scripts/util/userTransfer.php.
 */
function pmssUserTransferMain(array $argv): int
{
    try {
        pmssUserTransferAssertRoot();
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

        $password = pmssUserTransferReadPassword();
        putenv('PMSS_USER_TRANSFER_PASSWORD='.$password);

        $scratch = pmssUserTransferScratchDir();
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
