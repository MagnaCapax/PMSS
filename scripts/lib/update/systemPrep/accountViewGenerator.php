<?php
/** Build the four account database views used by one session. PHP 7.3 compatible. */

/** Parse a passwd database, retaining the exact selected record. */
function pmssAccountViewIdentity(string $passwd, ?string $name, ?int $requestedUid): ?array
{
    foreach (explode("\n", $passwd) as $line) {
        $fields = explode(':', $line);
        if (count($fields) < 7 || !ctype_digit($fields[2]) || !ctype_digit($fields[3])) continue;
        $uid = (int) $fields[2];
        if ($uid < 1000) continue;
        if (($name !== null && $fields[0] === $name) || ($requestedUid !== null && $uid === $requestedUid)) {
            return ['name' => $fields[0], 'uid' => $uid, 'gid' => (int) $fields[3]];
        }
    }
    return null;
}

/** Filter source text using parsed records; names are never used in paths. */
function pmssAccountViewFilter(string $kind, string $source, array $identity, string $passwd): string
{
    $systemNames = [];
    foreach (explode("\n", $passwd) as $line) {
        $fields = explode(':', $line);
        if (count($fields) >= 7 && ctype_digit($fields[2])) {
            $uid = (int) $fields[2];
            if ($uid < 1000 || $uid > 60000) $systemNames[$fields[0]] = true;
        }
    }
    $systemNames[$identity['name']] = true;
    $result = [];
    foreach (explode("\n", $source) as $line) {
        $fields = explode(':', $line);
        if ($kind === 'passwd') {
            if (count($fields) < 7 || !ctype_digit($fields[2])) continue;
            $uid = (int) $fields[2];
            if ($uid < 1000 || $uid > 60000 || $uid === $identity['uid']) $result[] = $line;
        } elseif ($kind === 'group') {
            if (count($fields) < 4 || !ctype_digit($fields[2])) continue;
            $members = array_filter(explode(',', $fields[3]), static function ($member) use ($systemNames): bool {
                return isset($systemNames[$member]);
            });
            if ((int) $fields[2] < 1000 || (int) $fields[2] > 60000 ||
                (int) $fields[2] === $identity['gid'] || in_array($identity['name'], explode(',', $fields[3]), true)) {
                $fields[3] = implode(',', $members);
                $result[] = implode(':', $fields);
            }
        } elseif (($kind === 'subuid' || $kind === 'subgid') && count($fields) === 3 &&
            $fields[0] === $identity['name'] && ctype_digit($fields[1]) && ctype_digit($fields[2])) {
            $result[] = $line;
        }
    }
    return $result ? implode("\n", $result)."\n" : '';
}

/** Require root ownership on each managed directory before writing a view. */
function pmssAccountViewEnsureDir(string $path, bool $private = true): bool
{
    if (!is_dir($path) && !@mkdir($path, $private ? 0700 : 0755)) return false;
    $stat = @lstat($path);
    $owner = (function_exists('posix_geteuid') && posix_geteuid() !== 0) ? posix_geteuid() : 0;
    return is_array($stat) && !is_link($path) && ($stat['mode'] & 0170000) === 0040000
        && $stat['uid'] === $owner && (!$private || @chmod($path, 0700));
}

/** Refuse a managed tree below an unexpected owner or a symlinked ancestor. */
function pmssAccountViewParentsSafe(string $path): bool
{
    $owner = (function_exists('posix_geteuid') && posix_geteuid() !== 0) ? posix_geteuid() : 0;
    for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
        $stat = @lstat($parent);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 ||
            ($stat['uid'] !== 0 && $stat['uid'] !== $owner)) return false;
    }
    return true;
}

/** Return success only after all present source files have complete, root-owned views. */
function pmssAccountViewGenerate(?string $name, ?int $requestedUid, string $sourceRoot = '/etc', string $viewRoot = '/var/lib/pmss/account-view', ?int &$resolvedUid = null): bool
{
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0 && ($sourceRoot === '/etc' || $viewRoot === '/var/lib/pmss/account-view')) return false;
    $passwd = @file_get_contents($sourceRoot.'/passwd');
    if (!is_string($passwd)) return false;
    $identity = pmssAccountViewIdentity($passwd, $name, $requestedUid);
    if ($identity === null) return false;
    $uid = $identity['uid'];
    $resolvedUid = $uid;
    if (!pmssAccountViewParentsSafe(dirname($viewRoot)) || !pmssAccountViewEnsureDir(dirname($viewRoot), false) ||
        !pmssAccountViewParentsSafe($viewRoot) || !pmssAccountViewEnsureDir($viewRoot) ||
        !pmssAccountViewEnsureDir($viewRoot.'/'.$uid)) return false;
    $dir = $viewRoot.'/'.$uid;
    $lock = @fopen($dir.'/.lock', 'c');
    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return is_file($dir.'/passwd') && is_file($dir.'/group') && is_file($dir.'/subuid') && is_file($dir.'/subgid');
    }
    @chmod($dir.'/.lock', 0600);
    $ok = true;
    foreach (['passwd', 'group', 'subuid', 'subgid'] as $kind) {
        foreach (['', '-'] as $suffix) {
            $file = $kind.$suffix;
            $source = $sourceRoot.'/'.$file;
            if (!is_file($source)) {
                if ($suffix === '') $ok = false;
                continue;
            }
            // Preserve nanoseconds so the next session only refreshes on a real source change.
            $mtime = trim((string) @shell_exec('/usr/bin/stat -c %y -- '.escapeshellarg($source).' 2>/dev/null'));
            $raw = @file_get_contents($source);
            if (!is_string($raw) || $mtime === '') { $ok = false; continue; }
            $filtered = pmssAccountViewFilter($kind, $raw, $identity, $passwd);
            $target = $dir.'/'.$file;
            $tmp = @tempnam($dir, '.view-');
            $written = $tmp !== false && @file_put_contents($tmp, $filtered) !== false;
            $touchRc = 1;
            if ($written) {
                @exec('/usr/bin/touch -d '.escapeshellarg($mtime).' -- '.escapeshellarg($tmp).' 2>/dev/null', $ignored, $touchRc);
            }
            if (!$written ||
                (function_exists('posix_geteuid') && posix_geteuid() === 0 && !@chown($tmp, 0)) ||
                !@chmod($tmp, 0644) || $touchRc !== 0 || !@rename($tmp, $target)) {
                if ($tmp !== false) @unlink($tmp);
                $ok = false;
            }
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $ok;
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $uid = null;
    $name = null;
    if ($argc === 3 && $argv[1] === '--uid' && ctype_digit($argv[2])) $uid = (int) $argv[2];
    elseif ($argc === 2 && $argv[1] !== '' && strpos($argv[1], ':') === false) $name = $argv[1];
    $resolvedUid = $uid;
    if (($uid === null && $name === null) || !pmssAccountViewGenerate($name, $uid, '/etc', '/var/lib/pmss/account-view', $resolvedUid)) {
        openlog('pmss-account-view', LOG_PID, LOG_AUTHPRIV);
        syslog(LOG_ERR, 'generation failed uid='.($resolvedUid === null ? 'unknown' : $resolvedUid));
        closelog();
        exit(1);
    }
}
