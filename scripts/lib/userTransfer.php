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
 * Ensure the caller is root (best effort; depends on posix extension).
 */
function pmssUserTransferAssertRoot(): void
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        throw new RuntimeException('This script must be run as root', 1);
    }
}

/**
 * Read a password from env or from an interactive TTY prompt.
 */
function pmssUserTransferReadPassword(): string
{
    $fromEnv = getenv('PMSS_USER_TRANSFER_PASSWORD');
    if ($fromEnv !== false && $fromEnv !== '') {
        return $fromEnv;
    }

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
    return $pass1;
}

/**
 * Create a private scratch directory under /root for temporary scripts.
 */
function pmssUserTransferScratchDir(): string
{
    try {
        $token = bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        $token = sha1(microtime(true).'-'.mt_rand());
    }
    $dir = '/root/pmss-userTransfer-'.$token;
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create scratch directory: '.$dir, 1);
    }
    @chmod($dir, 0700);
    return $dir;
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
