<?php
/**
 * Customer-side Deluge service password helpers.
 *
 * This file is bundled into each customer's www tree. It must remain
 * self-contained: customer PHP cannot traverse /scripts/.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';

/** Read the localclient password from a Deluge auth file. */
function pmssDelugeAuthReadLocalclientPassword(string $authPath): string
{
    if (!is_file($authPath) || is_link($authPath)) {
        return '';
    }

    $content = @file_get_contents($authPath);
    if (!is_string($content) || $content === '') {
        return '';
    }

    return preg_match('/^localclient:([^:\r\n]+):[0-9]+$/m', $content, $matches) === 1 ? $matches[1] : '';
}

/** Return the configured home root without a trailing slash. */
function pmssUserPasswordsHomeRoot(): string
{
    return pmssCustomerHomeRoot();
}

function pmssUserPasswordsPath(string $username, string $leaf): string
{
    return pmssCustomerHomePath(pmssUserPasswordsHomeRoot().'/'.$username, '.config/deluge/'.$leaf);
}

/** Accept only non-symlink paths under the configured home root. */
function pmssUserPasswordsPathIsSafe(string $path): bool
{
    return pmssCustomerPathIsSafe($path);
}

/** Write through a same-directory temp file so partial writes do not leak. */
function pmssUserPasswordsAtomicWrite(string $path, string $contents, int $mode = 0600): bool
{
    $dir = dirname($path);
    if (!pmssUserPasswordsPathIsSafe($path) || !is_dir($dir) || !is_writable($dir)) {
        return false;
    }

    $tmp = @tempnam($dir, '.pmss-pw-');
    if ($tmp === false || @file_put_contents($tmp, $contents) === false) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        return false;
    }

    @chmod($tmp, $mode);
    if (@rename($tmp, $path)) {
        return true;
    }

    @unlink($tmp);
    return false;
}

function pmssUserPasswordsAuthWrite(string $authPath, string $password): bool
{
    if ($password === '' || strpos($password, ':') !== false || preg_match('/[\r\n]/', $password) === 1) {
        return false;
    }

    $lines = @file($authPath, FILE_IGNORE_NEW_LINES);
    $lines = is_array($lines) ? $lines : array();
    $updated = false;
    foreach ($lines as $index => $line) {
        if (preg_match('/^localclient:[^:\r\n]*:[0-9]+$/', $line) === 1) {
            $lines[$index] = 'localclient:'.$password.':10';
            $updated = true;
            break;
        }
    }

    if (!$updated) {
        $lines[] = 'localclient:'.$password.':10';
    }

    return pmssUserPasswordsAtomicWrite($authPath, implode("\n", $lines)."\n", 0600);
}

/**
 * Parse Deluge web.conf's two concatenated JSON objects.
 *
 * @return array{meta:array<string,mixed>,config:array<string,mixed>}|null
 */
function pmssUserPasswordsWebConfRead(string $path): ?array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || strpos($raw, "\0") !== false) {
        return null;
    }

    $start = strspn($raw, " \t\n\r");
    $depth = 0;
    $inString = false;
    $escape = false;
    $end = null;
    for ($i = $start, $length = strlen($raw); $i < $length; $i++) {
        $ch = $raw[$i];
        if ($inString) {
            if ($escape) { $escape = false; continue; }
            if ($ch === '\\') { $escape = true; continue; }
            if ($ch === '"') { $inString = false; }
            continue;
        }
        if ($ch === '"') { $inString = true; continue; }
        if ($ch === '{') { $depth++; continue; }
        if ($ch === '}' && --$depth === 0) { $end = $i + 1; break; }
    }

    if ($end === null) {
        return null;
    }

    $meta = json_decode(substr($raw, $start, $end - $start), true);
    $config = json_decode(ltrim(substr($raw, $end)), true);
    return is_array($meta) && is_array($config) ? array('meta' => $meta, 'config' => $config) : null;
}

function pmssUserPasswordsWebConfWrite(string $path, array $meta, array $config): bool
{
    $metaJson = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($metaJson === false || $configJson === false) {
        return false;
    }

    $perms = @fileperms($path);
    return pmssUserPasswordsAtomicWrite($path, $metaJson.$configJson, is_int($perms) ? ($perms & 0777) : 0600);
}

function pmssUserPasswordsGenerate(int $length = 24): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
    $password = '';
    for ($i = 0, $max = strlen($alphabet) - 1; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

if (!function_exists('pmssDelugeServicePasswordRotate')) {
    /** Rotate daemon auth and Web UI hash together, restoring web.conf on failure. */
    function pmssDelugeServicePasswordRotate(string $username): string
    {
        if ($username === '' || preg_match('/^[a-z][a-z0-9]{0,7}$/', $username) !== 1) {
            return '';
        }

        $authPath = pmssUserPasswordsPath($username, 'auth');
        $webConfPath = pmssUserPasswordsPath($username, 'web.conf');
        $original = pmssUserPasswordsWebConfRead($webConfPath);
        if (!is_array($original) || !pmssUserPasswordsPathIsSafe($authPath) || !pmssUserPasswordsPathIsSafe($webConfPath)) {
            return '';
        }

        $password = pmssUserPasswordsGenerate();
        $config = $original['config'];
        $salt = (string) ($config['pwd_salt'] ?? '');
        $salt = strlen($salt) === 40 && ctype_xdigit($salt) ? $salt : bin2hex(random_bytes(20));
        $config['pwd_salt'] = $salt;
        $config['pwd_sha1'] = sha1($salt.$password);
        if (array_key_exists('sessions', $config) && is_array($config['sessions']) && count($config['sessions']) === 0) {
            $config['sessions'] = (object) array();
        }

        if (pmssUserPasswordsWebConfWrite($webConfPath, $original['meta'], $config)
            && pmssUserPasswordsAuthWrite($authPath, $password)) {
            return $password;
        }

        pmssUserPasswordsWebConfWrite($webConfPath, $original['meta'], $original['config']);
        return '';
    }
}
