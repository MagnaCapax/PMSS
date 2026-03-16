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

foreach (['cliParse', 'localUserSafety'] as $module) {
    require_once __DIR__.'/userTransfer/'.$module.'.php';
}

/**
 * Write a file with the given contents and permissions.
 */
function pmssUserTransferWriteFile(string $path, string $contents, int $mode): void
{
    if (@file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Failed writing: '.$path, 1);
    }
    @chmod($path, $mode);
}

/**
 * Sleep between passes (optionally randomised) while logging the reason.
 */
function pmssUserTransferSleep(int $min, int $max, string $reason): void
{
    // Dry runs should never stall for long-running sleeps.
    if (getenv('PMSS_DRY_RUN') === '1' || $max <= 0) {
        return;
    }

    $seconds = $min;
    if ($max > $min) {
        try {
            $seconds = random_int($min, $max);
        } catch (Throwable $e) {
            $seconds = rand($min, $max);
        }
    }

    logMessage(sprintf('[SLEEP] %s (%ds)', $reason, $seconds));
    sleep($seconds);
}

require_once __DIR__.'/userTransfer/main.php';
