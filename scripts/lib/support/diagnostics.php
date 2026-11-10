<?php
/**
 * Support diagnostics facade and private snapshot persistence.
 *
 * Collection and identity observations are loaded behind the original include;
 * this module owns snapshot publication and its failure cleanup.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['collect.php', 'stream.php', '../lighttpd/userFileWrite.php']);

/**
 * Persist the diagnostics snapshot under the caller's home directory.
 */
function pmssSupportSnapshotWrite(array $diagnostics, array $config): string
{
    $home = rtrim((string) ($diagnostics['home'] ?? ''), '/');
    if ($home === '' || $home[0] !== '/' || !is_dir($home) || !pmssPathTargetIsSafe($home, true)) {
        throw new RuntimeException('Unable to determine support snapshot home directory.');
    }

    $snapshotDir = $home.'/'.trim((string) $config['snapshotDirectory'], '/');
    if (!pmssPathTargetIsSafe($snapshotDir, true)) {
        throw new RuntimeException('Support snapshot directory is unsafe.');
    }
    if (!pmssDirEnsureExists($snapshotDir, 0700)) {
        throw new RuntimeException('Unable to create support snapshot directory.');
    }
    if (!is_dir($snapshotDir) || !pmssPathTargetIsSafe($snapshotDir, true)) {
        throw new RuntimeException('Support snapshot directory is unsafe.');
    }
    @chmod($snapshotDir, 0700);
    clearstatcache(true, $snapshotDir);
    $snapshotDirMode = @fileperms($snapshotDir);
    if (is_int($snapshotDirMode) && (($snapshotDirMode & 0777) !== 0700)) {
        throw new RuntimeException('Support snapshot directory permission check failed.');
    }

    $path = sprintf('%s/request-%s-%d.txt', $snapshotDir, gmdate('Ymd-His'), getmypid());
    $handle = pmssFileOpenWithUmask($path, 'x', 0077);
    if ($handle === false) {
        throw new RuntimeException('Unable to create support snapshot file.');
    }

    $deletePath = true;
    try {
        pmssSupportStreamWriteAll($handle, (string) ($diagnostics['body'] ?? ''), 'support snapshot file');
        if (@fflush($handle) !== true) {
            throw new RuntimeException('Unable to flush support snapshot file.');
        }
        $deletePath = false;
    } finally {
        try {
            @fclose($handle);
        } finally {
            // A close failure must not prevent removal of an incomplete snapshot.
            if ($deletePath) {
                @unlink($path);
            }
        }
    }

    if (!pmssRegularFilePathIsReadable($path) || (function_exists('posix_geteuid') && @fileowner($path) !== posix_geteuid())) {
        @unlink($path);
        throw new RuntimeException('Support snapshot file ownership check failed.');
    }

    @chmod($path, 0600);
    clearstatcache(true, $path);
    $snapshotFileMode = @fileperms($path);
    if (is_int($snapshotFileMode) && (($snapshotFileMode & 0777) !== 0600)) {
        @unlink($path);
        throw new RuntimeException('Support snapshot file permission check failed.');
    }

    return $path;
}
