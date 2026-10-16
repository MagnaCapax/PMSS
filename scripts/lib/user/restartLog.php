<?php
require_once dirname(__DIR__).'/pathSafety.php';

/** Append exactly one safe JSON audit record for a restart invocation. */
function pmssRestartUserLogWrite(array $record, string $path = '/var/log/pmss/restartUser.log'): bool
{
    // Reject malformed input before creating the log directory or target file.
    if (preg_match('/[\r\n\0]/', $path) === 1 || !pmssPathSegmentsAreSafe($path, true)) return false;
    $json = json_encode($record, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) return false;

    $dir = dirname($path);
    if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0755, true))
        || !pmssPathSegmentsAreSafe($path, true)
        || is_link($path) || (file_exists($path) && !is_file($path))) return false;
    if ($path === '/var/log/pmss/restartUser.log'
        && (fileowner($dir) !== 0 || (fileperms($dir) & 0022) !== 0)) return false;

    $created = !file_exists($path);
    $oldUmask = umask(0137);
    try {
        $handle = @fopen($path, 'ab');
    } finally {
        umask($oldUmask);
    }
    if ($handle === false) return false;
    try {
        clearstatcache(true, $path);
        $opened = fstat($handle);
        $linked = @lstat($path);
        if (!is_array($opened) || !is_array($linked) || ($linked['mode'] & 0170000) !== 0100000
            || $opened['ino'] !== $linked['ino'] || $opened['dev'] !== $linked['dev']) return false;
        if ($created && (!@chmod($path, 0640) || !@chgrp($path, 'adm'))) return false;
        return flock($handle, LOCK_EX)
            && fwrite($handle, $json."\n") === strlen($json) + 1 && fflush($handle);
    } finally {
        fclose($handle);
    }
}
