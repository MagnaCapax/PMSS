<?php
/** Track command failures during one user-permission repair invocation. */

/** Run a repair command, retaining the first failure for the final summary. */
function pmssUserPermissionsRun(string $command): void
{
    if (pmssRun($command) === 0) {
        return;
    }

    if (empty($GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'])) {
        $GLOBALS['PMSS_USER_PERMISSIONS_FIRST_FAILED_COMMAND'] = $command;
    }
    $GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'] = ($GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'] ?? 0) + 1;
}

/** Report all failed commands once, after the remaining repairs have run. */
function pmssUserPermissionsResult(): int
{
    $failedCount = $GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'] ?? 0;
    if ($failedCount === 0) {
        return 0;
    }

    fwrite(STDERR, sprintf(
        "userPermissions: %d command(s) failed; first: %s\n",
        $failedCount,
        $GLOBALS['PMSS_USER_PERMISSIONS_FIRST_FAILED_COMMAND']
    ));
    return 1;
}
