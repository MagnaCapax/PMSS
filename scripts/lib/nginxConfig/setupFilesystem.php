<?php
/** Fail-soft global nginx filesystem operations. Loaded by setup.php. */
/** Report failed global config writes without changing the setup flow. */
function pmssCreateNginxConfigSetupWarn(string $operation, string $path): void
{
    $message = '[WARN] Failed to '.$operation.' nginx config: '.$path;
    fwrite(STDERR, $message.PHP_EOL);
}

/** Copy a global template and report failed attempts. */
function pmssCreateNginxConfigSetupCopy(string $source, string $target): bool
{
    if (@copy($source, $target)) return true;
    pmssCreateNginxConfigSetupWarn('copy template to', $target);
    return false;
}

/** Check the complete byte count so a short global config write is visible. */
function pmssCreateNginxConfigSetupWrite(string $path, string $content): bool
{
    if (pmssFileWriteComplete($path, $content)) return true;
    pmssCreateNginxConfigSetupWarn('write', $path);
    return false;
}

/** Report directory preparation failures while retaining fail-soft setup. */
function pmssCreateNginxConfigSetupDirectory(string $path): bool
{
    if (pmssDirEnsureExists($path, 0755)) return true;
    pmssCreateNginxConfigSetupWarn('prepare directory for', $path);
    return false;
}

/** Keep the existing stale-default guards and report a failed removal. */
function pmssCreateNginxConfigSetupRemoveDefault(string $path): void
{
    if (is_file($path) && !is_link($path) && pmssPathTargetIsSafe($path, false, true)) {
        if (!@unlink($path)) pmssCreateNginxConfigSetupWarn('remove obsolete account UI default', $path);
    }
}
