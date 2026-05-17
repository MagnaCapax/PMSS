<?php
/** Guard /home-touching scripts when the dedicated home filesystem is absent.
 * @license GPL-3.0-only
 */
function pmssIsHomeMounted(): bool
{
    $mounts = @file_get_contents(((string) getenv('PMSS_PROC_MOUNTS_PATH')) ?: '/proc/mounts');
    return is_string($mounts) && preg_match('/^\\s*\\S+\\s+\\/home\\s+/m', $mounts) === 1;
}

/** Abort with a clear error if the required /home mount is absent. */
function pmssRequireHomeMounted(string $context = ''): void
{
    $skip = strtolower((string) getenv('PMSS_SKIP_HOME_MOUNT_CHECK'));
    if ($skip === '1' || $skip === 'true' || pmssIsHomeMounted()) {
        return;
    }

    fwrite(STDERR, ($context !== '' ? "[{$context}] " : '')."Error: /home is not mounted as a separate filesystem.\n"
        ."PMSS requires /home to be mounted. Aborting to prevent data loss.\n"
        ."If this is intentional (non-standard deployment), set PMSS_SKIP_HOME_MOUNT_CHECK=1.\n");
    exit(1);
}
