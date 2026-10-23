<?php
/**
 * Managed file writing helpers shared by lighttpd and other PMSS writers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../log.php';
require_once __DIR__.'/../runtime/filesystem.php';
require_once __DIR__.'/accountPath.php';
require_once __DIR__.'/rootStage.php';

/** Internal modules are loaded together; callers keep this facade as their entry point. */
const PMSS_USER_FILE_MODULES = [
    'userFilePath.php', 'userFileHandle.php', 'userFileMetadata.php',
    'userFileReplace.php', 'userFileAccount.php', 'userFileSerialized.php',
];
(static function (): void { foreach (PMSS_USER_FILE_MODULES as $module) require_once __DIR__.'/'.$module; })();

/** Persist a numeric network port through the shared symlink-safe writer. */
function pmssNetworkPortFileWrite(string $path, int $port, int $min = 1, int $max = 65535, int $fallbackMode = 0640): bool
{
    return pmssNetworkPortInRange($port, $min, $max)
        && pmssReplaceUserFilePreservingMetadata($path, (string) $port, $fallbackMode);
}

function pmssAtomicWriteFile(string $path, string $content, ?int $mode = null): bool
{
    if ($mode === null && pmssManagedAccountHomePath($path)) {
        return pmssRootStageFileWrite($path, $content, 'root', 0600);
    }
    return $mode === null ? pmssReplaceUserFile($path, $content) : pmssReplaceUserFileWithMetadata($path, $content, $mode);
}

/** Encode and atomically publish one newline-terminated pretty JSON document. */
function pmssAtomicJsonFileWrite(string $path, array $payload, int $mode): bool
{
    $encoded = pmssJsonEncodePrettyLine($payload);
    if (pmssManagedAccountHomePath($path)) {
        return is_string($encoded) && pmssReplaceUserFileWithMetadata($path, $encoded, $mode);
    }
    return is_string($encoded) && pmssReplaceUserFile(
        $path,
        $encoded,
        static function (string $temporaryPath) use ($mode): bool {
            return @chmod($temporaryPath, $mode);
        }
    );
}

function pmssWriteManagedFile(string $path, string $content, string $owner, ?string $group, int $mode): bool
{
    return pmssReplaceUserFileWithMetadata($path, $content, $mode, $owner, $group);
}

function pmssWriteUserFile(string $path, string $content, string $owner, int $mode): bool
{
    return pmssWriteManagedFile($path, $content, $owner, $owner, $mode);
}
