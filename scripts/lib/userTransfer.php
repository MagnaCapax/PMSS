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
require_once __DIR__.'/update/runtime/commands.php';
require_once __DIR__.'/userTransfer/cliParse.php';
require_once __DIR__.'/userTransfer/postSetup.php';
require_once __DIR__.'/userTransfer/transferRuntime.php';

/** Build scratch script payloads keyed to pmssUserTransferScratchPaths(). */
function pmssUserTransferScratchPayloads(array $cfg, array $paths = []): array { $paths = $paths ?: pmssUserTransferScratchPaths('/root/pmss-userTransfer-<generated>'); return ['expect' => pmssUserTransferBuildExpectWrapper()."\n", 'authProbe' => pmssUserTransferBuildAuthProbe($cfg), 'mainScript' => pmssUserTransferBuildRsyncMain($cfg), 'finalScript' => pmssUserTransferBuildRsyncFinal($cfg), 'remoteSizeScript' => pmssUserTransferBuildRemoteSizeProbe($cfg), 'qbittorrentProbeScript' => pmssUserTransferBuildQbittorrentCategoryProbe($cfg, $paths['qbittorrentConfig'], $paths['qbittorrentCategories'])]; }

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
            runStep('Normalising user permissions', pmssBuildCommand('php', [dirname(__DIR__).'/../util/userPermissions.php', $cfg['localUser']]));
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

        foreach (pmssUserTransferScratchPayloads($cfg, $scratchPaths) as $key => $contents) {
            pmssUserTransferWriteFile($scratchPaths[$key], $contents, 0700);
        }

        try {
            // Validate credentials first so a bad password does not burn dozens of rsync passes.
            $authRc = runStep('Validating remote SSH authentication', pmssBuildCommand($scratchPaths['expect'], [$scratchPaths['authProbe']]));
            if ($authRc !== 0) {
                logMessage(sprintf('[ERR] Aborting user transfer: remote authentication pre-flight failed (rc=%d)', $authRc));
                return 1;
            }

            $lastMainRc = pmssUserTransferRunPasses('Pulling home data', $scratchPaths['expect'], $scratchPaths['mainScript'], $cfg['mainPasses'], $cfg['sleepMin'], $cfg['sleepMax']);
            $lastFinalRc = pmssUserTransferRunPasses('Pulling volatile data', $scratchPaths['expect'], $scratchPaths['finalScript'], $cfg['finalPasses'], $cfg['sleepMin'], $cfg['sleepMax']);

            pmssUserTransferPostSetup($cfg, $home, $scratchPaths);

            if ($cfg['printPassword']) {
                logMessage('[WARN] Remote password: '.$password);
            }

            if ($lastMainRc !== 0 || $lastFinalRc !== 0) {
                logMessage(sprintf('[WARN] User transfer finished with errors (main rc=%d, final rc=%d)', $lastMainRc, $lastFinalRc));
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
