<?php
/**
 * User transfer CLI usage text.
 *
 * @license GPL-3.0-only
 */

/**
 * Return the CLI usage text.
 */
function pmssUserTransferUsage(): string
{
    return <<<TXT
Usage:
  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_HOSTNAME
  /scripts/util/userTransfer.php LOCAL_USERNAME REMOTE_USERNAME REMOTE_HOSTNAME

Options:
  --main-passes N     Number of passes for the main rsync (default 31)
  --final-passes N    Number of passes for the final rsync (default 3)
  --sleep-min N       Minimum sleep seconds between passes (default 60)
  --sleep-max N       Maximum sleep seconds between passes (default 360)
  --no-sleep          Disable sleeping between passes
  --dry-run           Log planned steps without executing commands
  --print-password    Print the supplied password at the end (unsafe)
  --help, -h          Show this help

Notes:
  - If REMOTE_HOSTNAME does not contain a dot, ".pulsedmedia.com" is appended.
  - Password can be provided via env: PMSS_USER_TRANSFER_PASSWORD

TXT;
}

