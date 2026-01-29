<?php
/**
 * Sleep helper for user transfers.
 *
 * @license GPL-3.0-only
 */

/**
 * Sleep between passes (optionally randomised) while logging the reason.
 */
function pmssUserTransferSleep(int $min, int $max, string $reason): void
{
    // Dry runs should never stall for long-running sleeps.
    if (getenv('PMSS_DRY_RUN') === '1') {
        return;
    }
    if ($max <= 0) {
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

