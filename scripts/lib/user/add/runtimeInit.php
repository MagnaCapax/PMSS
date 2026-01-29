<?php
/**
 * addUser provisioning runtime initialization.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Initialise runtime knobs used by addUser provisioning runs.
 *
 * - Removes time limits in CLI runs (best-effort).
 * - Continues execution if the SSH session dies (best-effort).
 */
function pmssAddUserRuntimeInit(): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    @ignore_user_abort(true);

    /**
     * Best-effort detachment from a dying SSH session in non-interactive runs.
     *
     * When automation launches addUser.php without a TTY, a backend timeout can
     * close the SSH channel and deliver SIGHUP/SIGPIPE. Ignoring those signals
     * helps the provisioning continue while logs are written to disk.
     */
    if (function_exists('posix_isatty')) {
        $hasTty = @posix_isatty(STDIN) || @posix_isatty(STDOUT) || @posix_isatty(STDERR);
        if (!$hasTty) {
            if (function_exists('posix_setsid')) {
                @posix_setsid();
            }
            if (function_exists('pcntl_signal')) {
                if (defined('SIGHUP')) {
                    @pcntl_signal(SIGHUP, SIG_IGN);
                }
                if (defined('SIGPIPE')) {
                    @pcntl_signal(SIGPIPE, SIG_IGN);
                }
            }
        }
    }
}

