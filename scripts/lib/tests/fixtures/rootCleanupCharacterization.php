<?php
/** In-memory filesystem and commands for root cleanup characterization; no host mutations. */
namespace RootCleanupCharacterization;

function option($key, $default = null) { return $GLOBALS['cleanupOptions'][$key] ?? $default; }
function record($event) { $GLOBALS['cleanupTrace'][] = $event; }
function time() { return 1000000; }
function date($format) { return '2026-01-01T00:00:00+00:00'; }
function gethostname() { return 'fixture.example'; }
function disk_total_space($path) { return option('total', 10000); }
function disk_free_space($path) {
    return $GLOBALS['cleanupApplying'] ? option('freeAfter', 2000) : option('free', 500);
}
function is_dir($path) {
    return in_array($path, ['/var/cache/apt/archives', '/var/log/journal', '/var/tmp/mkinitramfs_fixture'], true)
        || ($path === '/var/log' && !option('missingLogs', false));
}
function is_file($path) { return isset($GLOBALS['cleanupFiles'][$path]) && !option('missingFiles', false); }
function realpath($path) {
    $redirects = $GLOBALS['cleanupApplying'] ? option('redirectAfterScan', []) : option('redirects', []);
    return array_key_exists($path, $redirects) ? $redirects[$path] : $path;
}
function filemtime($path) { return option('mtime', 1); }
function filesize($path) { return $GLOBALS['cleanupFiles'][$path] ?? 0; }
function glob($pattern) {
    record(['glob', $pattern]);
    if (option('empty', false)) return false;
    return strpos($pattern, '/var/log/atop/') === 0
        ? ['/var/log/atop/atop_20260101'] : ['/var/tmp/mkinitramfs_fixture'];
}
function unlink($path) {
    record(['unlink', $path]);
    return !in_array($path, option('unlinkFailures', []), true);
}
function file_exists($path) { record(['exists', $path]); return option('scratchRemains', false); }
function shell_exec($command) {
    record(['command', $command]);
    if (strpos($command, 'du -sb ') === 0) {
        if (strpos($command, '/var/cache/apt/archives') !== false) return $GLOBALS['cleanupSizes']['apt']."\tfixture\n";
        if (strpos($command, '/var/log/journal') !== false) return $GLOBALS['cleanupSizes']['journal']."\tfixture\n";
        return "50\tfixture\n";
    }
    $GLOBALS['cleanupApplying'] = true;
    if (strpos($command, 'apt-get clean') === 0) $GLOBALS['cleanupSizes']['apt'] = option('aptAfter', 0);
    if (strpos($command, 'journalctl ') === 0) $GLOBALS['cleanupSizes']['journal'] = option('journalAfter', 209715200);
    return null;
}
function pmssLogWriteDirectoryPrepare($path, $mode, &$error) {
    record(['audit-directory', $path, $mode]);
    return !option('auditDirectoryFailure', false);
}
function pmssLogWritePathIsSafe($path) { record(['audit-path', $path]); return !option('auditUnsafe', false); }
function pmssJsonLineAppend($path, $entry) { record(['json', $path, $entry]); return !option('auditWriteFailure', false); }

// Stand-ins keep iterator options visible and provide only synthetic file metadata.
class FilesystemIterator { const SKIP_DOTS = \FilesystemIterator::SKIP_DOTS; }
class RecursiveDirectoryIterator extends \ArrayIterator
{
    public function __construct($path, $flags) {
        record(['scan', $path, $flags]);
        parent::__construct(option('empty', false) ? [] : [
            new FileInfo('/var/log/syslog.1', option('rotatedMtime', 1)), new FileInfo('/var/log/atop/atop_20260101'),
            new FileInfo('/var/log/syslog'), new FileInfo('/var/log/recent.gz', 1000000),
            new FileInfo('/var/log/redirect.gz'), new FileInfo('/var/log/not-a-file.gz', 1, false),
        ]);
    }
}
class RecursiveIteratorIterator extends \IteratorIterator
{
    const LEAVES_ONLY = \RecursiveIteratorIterator::LEAVES_ONLY;
    const CATCH_GET_CHILD = \RecursiveIteratorIterator::CATCH_GET_CHILD;
    public function __construct($iterator, $mode, $flags) {
        record(['walk', $mode, $flags]);
        parent::__construct($iterator);
    }
}
class FileInfo
{
    private $path;
    private $mtime;
    private $file;
    public function __construct($path, $mtime = 1, $file = true) { $this->path = $path; $this->mtime = $mtime; $this->file = $file; }
    public function isFile() { return $this->file; }
    public function getPathname() { return $this->path; }
    public function getMTime() { return $this->mtime; }
    public function getSize() { return filesize($this->path); }
}
