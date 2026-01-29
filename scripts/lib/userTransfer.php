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

require_once __DIR__.'/userTransfer/cliUsage.php';
require_once __DIR__.'/userTransfer/hostnameValidate.php';
require_once __DIR__.'/userTransfer/cliParse.php';
require_once __DIR__.'/userTransfer/localUserSafety.php';
require_once __DIR__.'/userTransfer/scratchIo.php';
require_once __DIR__.'/userTransfer/scriptsBuild.php';
require_once __DIR__.'/userTransfer/sleep.php';
require_once __DIR__.'/userTransfer/postSetup.php';

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

require_once __DIR__.'/userTransfer/main.php';

