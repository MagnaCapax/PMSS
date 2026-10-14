<?php
/**
 * User transfer helper (library).
 *
 * Provides the implementation behind `scripts/util/userTransfer.php`.
 * The CLI entry point stays thin while the reusable logic lives here so it can
 * be tested hermetically and kept readable.
 *
 * Notes:
 * - Password can be provided via env: PMSS_USER_TRANSFER_PASSWORD
 * - This script must be run as root
 *
 * @author Aleksi Ursin
 * @copyright NuCode 2015-2025 - All Rights reserved.
 * @since 31/03/2015
 * @version 2.0
 *
 * @license GPL-3.0-only
 **/

require_once __DIR__.'/userLifecycle.php';
pmssRequireRelativeFiles(__DIR__, ['update/runtime/commands.php', 'userTransfer/cliParse.php', 'userTransfer/postSetup.php', 'userTransfer/transferRuntime.php']);

/** Build scratch script payloads keyed to pmssUserTransferScratchPaths(). */
function pmssUserTransferScratchPayloads(array $cfg, array $paths = []): array
{
    $paths = $paths ?: pmssUserTransferScratchPaths('/root/pmss-userTransfer-<generated>');
    return [
        'expect' => pmssUserTransferBuildExpectWrapper()."\n",
        'authProbe' => pmssUserTransferBuildAuthProbe($cfg),
        'identityProbe' => pmssUserTransferBuildIdentityProbe($cfg),
        'namespaceProbe' => pmssUserTransferBuildNamespaceProbe($cfg),
        'mainScript' => pmssUserTransferBuildRsyncMain($cfg),
        'finalScript' => pmssUserTransferBuildRsyncFinal($cfg),
        'remoteSizeScript' => pmssUserTransferBuildRemoteSizeProbe($cfg),
        'qbittorrentProbeScript' => pmssUserTransferBuildQbittorrentCategoryProbe($cfg, $paths['qbittorrentConfig'], $paths['qbittorrentCategories']),
        'dockerQuiesceScript' => pmssUserTransferBuildDockerQuiesce($cfg, $paths['dockerIds']),
    ];
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
        logMessage(sprintf(
            '[INFO] Main passes=%d Final passes=%d Sleep=%d..%d Verify threshold=%d%%',
            $cfg['mainPasses'],
            $cfg['finalPasses'],
            $cfg['sleepMin'],
            $cfg['sleepMax'],
            $cfg['verifyThreshold']
        ));

        // Dry runs should be fully non-interactive and avoid writing temp scripts.
        if ($cfg['dryRun']) {
            $scratchPaths = pmssUserTransferScratchPaths('/root/pmss-userTransfer-<generated>');
            runStep('Validating remote SSH authentication', pmssBuildCommand($scratchPaths['expect'], [$scratchPaths['authProbe']]));
            pmssUserTransferRunPasses('Pulling home data', $scratchPaths['expect'], $scratchPaths['mainScript'], 1, 0, 0);
            pmssUserTransferRunPasses('Pulling volatile data', $scratchPaths['expect'], $scratchPaths['finalScript'], 1, 0, 0);
            runStep('Normalising user permissions', pmssBuildCommand('php', [dirname(__DIR__).'/util/userPermissions.php', $cfg['localUser']]));
            logMessage('[SKIP] Dry run complete');
            return 0;
        }

        $password = pmssUserTransferResolvePassword();
        putenv('PMSS_USER_TRANSFER_PASSWORD='.$password);

        $scratch = pmssUserTransferCreateScratchRoot();
        $scratchPaths = pmssUserTransferScratchPaths($scratch);
        $cleanup = static function () use ($scratchPaths, $scratch): void {
            pmssUserTransferScratchCleanup($scratch, $scratchPaths);
        };
        register_shutdown_function($cleanup);

        pmssUserTransferWriteFile($scratchPaths['expect'], pmssUserTransferBuildExpectWrapper()."\n", 0700);
        pmssUserTransferWriteFile($scratchPaths['authProbe'], pmssUserTransferBuildAuthProbe($cfg), 0700);
        pmssUserTransferWriteFile($scratchPaths['identityProbe'], pmssUserTransferBuildIdentityProbe($cfg), 0700);

        try {
            // Validate credentials first so a bad password does not burn dozens of rsync passes.
            $authRc = runStep('Validating remote SSH authentication', pmssBuildCommand($scratchPaths['expect'], [$scratchPaths['authProbe']]));
            if ($authRc !== 0) {
                logMessage(sprintf('[ERR] Aborting user transfer: remote authentication pre-flight failed (rc=%d)', $authRc));
                return 1;
            }

            $identityRc = runStep('Reading source ownership ranges', pmssBuildCommand($scratchPaths['expect'], [$scratchPaths['identityProbe']]));
            if ($identityRc !== 0) {
                throw new RuntimeException('Source ownership probe failed', 1);
            }
            $source = pmssUserTransferParseIdentity((string) ($GLOBALS['PMSS_LAST_COMMAND_OUTPUT']['stdout'] ?? ''));
            $cfg['receiver'] = pmssUserTransferReceiverConfig($cfg['localUser'], $home, $source);
            pmssUserTransferWriteFile($scratchPaths['namespaceProbe'], pmssUserTransferBuildNamespaceProbe($cfg), 0700);
            $localProbe = pmssBuildCommand('env', [
                '-u', 'PMSS_USER_TRANSFER_PASSWORD', 'setpriv', '--reuid='.$cfg['receiver']['uid'],
                '--regid='.$cfg['receiver']['gid'], '--clear-groups',
                'unshare', '--map-auto', '--map-root-user', 'true',
            ]);
            $localRc = runStep('Probing target account user namespace', $localProbe);
            $remoteRc = runStep('Probing source account user namespace', pmssBuildCommand($scratchPaths['expect'], [$scratchPaths['namespaceProbe']]));
            $cfg['namespaceRsync'] = pmssUserTransferNamespaceRsyncAvailable($localRc, $remoteRc);
            if (!$cfg['namespaceRsync']) {
                logMessage('[INFO] Transfer rsync user namespace unavailable; using the existing receiver mapping');
            }
            foreach (pmssUserTransferScratchPayloads($cfg, $scratchPaths) as $key => $contents) {
                if ($key !== 'expect' && $key !== 'authProbe' && $key !== 'identityProbe' && $key !== 'namespaceProbe') {
                    pmssUserTransferWriteFile($scratchPaths[$key], $contents, 0700);
                }
            }

            $lastMainRc = pmssUserTransferRunPasses('Pulling home data', $scratchPaths['expect'], $scratchPaths['mainScript'], $cfg['mainPasses'], $cfg['sleepMin'], $cfg['sleepMax']);
            $lastFinalRc = pmssUserTransferRunPasses('Pulling volatile data', $scratchPaths['expect'], $scratchPaths['finalScript'], $cfg['finalPasses'], $cfg['sleepMin'], $cfg['sleepMax']);

            $dockerQuiesceRc = runStep('Quiescing source rootless Docker containers', pmssBuildCommand($scratchPaths['expect'], [$scratchPaths['dockerQuiesceScript']]));
            if ($dockerQuiesceRc !== 0) {
                logMessage(sprintf('[WARN] Docker quiesce probe returned rc=%d', $dockerQuiesceRc));
            }
            $dockerIds = pmssUserTransferValidDockerIds((string) @file_get_contents($scratchPaths['dockerIds']));
            $dockerCount = count($dockerIds);
            logMessage(sprintf('[INFO] Docker hand-over: %d running container(s) stopped on source', $dockerCount));
            $dockerFinalRc = 0;
            if ($dockerCount > 0) {
                $dockerFinalRc = pmssUserTransferRunPasses('Pulling volatile data (containers stopped)', $scratchPaths['expect'], $scratchPaths['finalScript'], 1, 0, 0);
            }

            pmssUserTransferPostSetup($cfg, $home, $scratchPaths);

            if ($dockerCount > 0) {
                if (pmssUserTransferDockerRcTransferred($dockerFinalRc)) {
                    $dockerUtility = dirname(__DIR__).'/util/userDocker.php';
                    runStep('Starting rootless Docker for '.$cfg['localUser'], pmssBuildCommand('php', [$dockerUtility, $cfg['localUser'], 'start']));
                    // runStep records its command; keep container IDs in private scratch.
                    $dockerStartCommand = pmssBuildCommand('php', array_merge([$dockerUtility, $cfg['localUser'], 'start-containers'], $dockerIds));
                    pmssUserTransferWriteFile($scratchPaths['dockerStartScript'], "#!/bin/bash\nset -e\n".$dockerStartCommand."\n", 0700);
                    runStep('Starting transferred containers', pmssBuildCommand($scratchPaths['dockerStartScript']));
                } else {
                    pmssUserTransferWriteFile($scratchPaths['dockerStartScript'], pmssUserTransferBuildDockerStartScript($cfg, $dockerIds), 0700);
                    runStep('Restarting source rootless Docker containers', pmssBuildCommand($scratchPaths['expect'], [$scratchPaths['dockerStartScript']]));
                    logMessage(sprintf('[WARN] Docker hand-over: final pass failed (rc=%d); restarted %d container(s) on source; target containers not started', $dockerFinalRc, $dockerCount));
                }
            }

            if ($cfg['printPassword']) {
                logMessage('[WARN] Remote password: '.$password);
            }

            if ($lastMainRc !== 0 || $lastFinalRc !== 0) {
                logMessage(sprintf('[WARN] User transfer finished with errors (main rc=%d, final rc=%d)', $lastMainRc, $lastFinalRc));
                return 1;
            }

            if ($dockerCount > 0 && !pmssUserTransferDockerRcTransferred($dockerFinalRc)) {
                return 1;
            }

            logMessage('[OK] User transfer complete');
            return 0;
        } finally {
            pmssUserTransferScratchCleanup($scratch, $scratchPaths);
            putenv('PMSS_USER_TRANSFER_PASSWORD');
        }
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
