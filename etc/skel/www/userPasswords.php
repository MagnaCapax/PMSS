<?php
/**
 * Customer-side password display helpers.
 *
 * Reads the customer's own Deluge auth file (~/.config/deluge/auth) and
 * exposes the localclient password to the welcome panel. Lives in
 * etc/skel/www/ per ADR 0016 — customer PHP runs as the customer UID and
 * cannot traverse /scripts/.
 *
 * Display-only subset. Rotation logic stays operator-side because it
 * touches deluged service state; until the operator-side rotation cron
 * lands, the welcome.php rotate-button branch keys off
 * pmssDelugeServicePasswordRotate which intentionally does not exist
 * here — customers see their current password but cannot rotate from
 * the panel yet.
 *
 * @license GPL-3.0-only
 */

if (!function_exists('pmssDelugeAuthPath')) {
    /** Resolve the Deluge auth file for a user under /home/<USER>/.config/deluge/auth. */
    function pmssDelugeAuthPath(string $username): string
    {
        $homeRoot = getenv('PMSS_HOME_DIR');
        if (!is_string($homeRoot) || trim($homeRoot) === '') {
            $homeRoot = '/home';
        }
        return rtrim($homeRoot, '/').'/'.$username.'/.config/deluge/auth';
    }
}

if (!function_exists('pmssDelugeAuthReadLocalclientPassword')) {
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
        return preg_match('/^localclient:([^:\r\n]+):[0-9]+$/m', $content, $matches) === 1
            ? $matches[1]
            : '';
    }
}

if (!function_exists('pmssDelugeWebConfPath')) {
    /** Resolve the Deluge Web UI config path under /home/<USER>/.config/deluge/web.conf. */
    function pmssDelugeWebConfPath(string $username): string
    {
        $homeRoot = getenv('PMSS_HOME_DIR');
        if (!is_string($homeRoot) || trim($homeRoot) === '') {
            $homeRoot = '/home';
        }
        return rtrim($homeRoot, '/').'/'.$username.'/.config/deluge/web.conf';
    }
}

if (!function_exists('pmssUserPasswordsCustomerFilePathIsSafe')) {
    /**
     * Customer-side path-safety check: path must be under the customer's own
     * home directory and not a symlink. Mirrors pmssUserFilePathIsSafe from
     * /scripts/lib/lighttpd/userFileWrite.php with a customer-tree-only
     * implementation (no traversal of /scripts/ required).
     */
    function pmssUserPasswordsCustomerFilePathIsSafe(string $path): bool
    {
        if ($path === '' || strpos($path, "\0") !== false || is_link($path)) {
            return false;
        }
        $homeRoot = getenv('PMSS_HOME_DIR');
        $homeRoot = (is_string($homeRoot) && trim($homeRoot) !== '') ? rtrim($homeRoot, '/') : '/home';
        $real = realpath($path);
        if ($real === false) {
            $real = realpath(dirname($path));
            if ($real === false) {
                return false;
            }
            $real .= '/'.basename($path);
        }
        return strpos($real, $homeRoot.'/') === 0;
    }
}

if (!function_exists('pmssUserPasswordsAtomicWrite')) {
    /** Atomic write via temp file in the same directory + rename. */
    function pmssUserPasswordsAtomicWrite(string $path, string $contents, int $mode = 0600): bool
    {
        if (!pmssUserPasswordsCustomerFilePathIsSafe($path)) {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        $tmp = @tempnam($dir, '.pmss-pw-');
        if ($tmp === false) {
            return false;
        }
        if (@file_put_contents($tmp, $contents) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}

if (!function_exists('pmssDelugeAuthWriteLocalclientPassword')) {
    /** Replace (or add) the localclient line in a Deluge auth file. */
    function pmssDelugeAuthWriteLocalclientPassword(string $authPath, string $password): bool
    {
        if ($password === '' || strpos($password, ':') !== false || preg_match('/[\r\n]/', $password) === 1) {
            return false;
        }
        if (!pmssUserPasswordsCustomerFilePathIsSafe($authPath)) {
            return false;
        }
        $lines = @file($authPath, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            $lines = [];
        }
        $replaced = false;
        foreach ($lines as $index => $line) {
            if (preg_match('/^localclient:[^:\r\n]*:[0-9]+$/', $line) === 1) {
                $lines[$index] = 'localclient:'.$password.':10';
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $lines[] = 'localclient:'.$password.':10';
        }
        return pmssUserPasswordsAtomicWrite($authPath, implode("\n", $lines)."\n", 0600);
    }
}

if (!function_exists('pmssDelugeWebConfRead')) {
    /**
     * Parse Deluge web.conf — two concatenated JSON objects (meta + config).
     * Inlined from /scripts/lib/lighttpd/userConfigApply.php for customer-side
     * rotation. Returns ['meta' => array, 'config' => array] or null on parse fail.
     */
    function pmssDelugeWebConfRead(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || strpos($raw, "\0") !== false) {
            return null;
        }
        $length = strlen($raw);
        $start = strspn($raw, " \t\n\r");
        if ($start >= $length || $raw[$start] !== '{') {
            return null;
        }
        $depth = 0;
        $inString = false;
        $escape = false;
        $firstObjectEnd = null;
        for ($index = $start; $index < $length; $index++) {
            $ch = $raw[$index];
            if ($inString) {
                if ($escape) { $escape = false; continue; }
                if ($ch === '\\') { $escape = true; continue; }
                if ($ch === '"') { $inString = false; }
                continue;
            }
            if ($ch === '"') { $inString = true; continue; }
            if ($ch === '{') { $depth++; continue; }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) { $firstObjectEnd = $index + 1; break; }
            }
        }
        if ($firstObjectEnd === null
            || !is_array($meta = json_decode(substr($raw, $start, $firstObjectEnd - $start), true))
            || !is_array($config = json_decode(ltrim(substr($raw, $firstObjectEnd)), true))) {
            return null;
        }
        return ['meta' => $meta, 'config' => $config];
    }
}

if (!function_exists('pmssDelugeWebConfWrite')) {
    /** Write the parsed meta + config back to web.conf as two concatenated JSON objects. */
    function pmssDelugeWebConfWrite(string $path, array $meta, array $config): bool
    {
        $metaJson = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($metaJson === false || $configJson === false) {
            return false;
        }
        $existingMode = @fileperms($path);
        $mode = is_int($existingMode) ? ($existingMode & 0777) : 0600;
        return pmssUserPasswordsAtomicWrite($path, $metaJson.$configJson, $mode);
    }
}

if (!function_exists('pmssDelugeServicePasswordGenerate')) {
    /** Generate a high-entropy Deluge service password (alphanumeric, ~144 bits at length 24). */
    function pmssDelugeServicePasswordGenerate(int $length = 24): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
        $maxIndex = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $maxIndex)];
        }
        return $password;
    }
}

if (!function_exists('pmssDelugeServicePasswordRotate')) {
    /**
     * Rotate the Deluge service credential: write the new password to BOTH
     * the auth file (daemon side) and web.conf (web UI side). On any failure
     * after web.conf is written, restore the original web.conf to keep the
     * two sides in sync. Returns the new password on success, '' on failure.
     *
     * Note: deluge daemon picks up the new auth file at next restart. The
     * customer-side restart path is the existing deluge.php endpoint
     * (POST to '../deluge.php?action=stop' then '?action=start' or similar).
     * Rotation here updates the credentials; daemon restart is the caller's
     * responsibility (welcome.php POST handler triggers it indirectly via
     * the same UI flow that customer uses for deluge enable/disable).
     */
    function pmssDelugeServicePasswordRotate(string $username): string
    {
        if ($username === '' || preg_match('/^[a-z][a-z0-9]{0,7}$/', $username) !== 1) {
            return '';
        }
        $authPath = pmssDelugeAuthPath($username);
        $webConfPath = pmssDelugeWebConfPath($username);
        if (!pmssUserPasswordsCustomerFilePathIsSafe($authPath)
            || !pmssUserPasswordsCustomerFilePathIsSafe($webConfPath)) {
            return '';
        }
        $original = pmssDelugeWebConfRead($webConfPath);
        if (!is_array($original) || !is_array($original['meta'] ?? null) || !is_array($original['config'] ?? null)) {
            return '';
        }
        $newPassword = pmssDelugeServicePasswordGenerate();
        // Compute the new pwd_salt + pwd_sha1 hash.
        $salt = (string) ($original['config']['pwd_salt'] ?? '');
        if (strlen($salt) !== 40 || !ctype_xdigit($salt)) {
            $salt = bin2hex(random_bytes(20));
        }
        $hash = sha1($salt.$newPassword);
        $config = $original['config'];
        $config['pwd_salt'] = $salt;
        $config['pwd_sha1'] = $hash;
        // Normalize empty sessions arrays back to a JSON object (Deluge expects {}).
        if (array_key_exists('sessions', $config) && is_array($config['sessions']) && count($config['sessions']) === 0) {
            $config['sessions'] = (object) [];
        }
        if (!pmssDelugeWebConfWrite($webConfPath, $original['meta'], $config)) {
            return '';
        }
        if (!pmssDelugeAuthWriteLocalclientPassword($authPath, $newPassword)) {
            // Restore web.conf so the daemon-side and web-side stay in sync.
            pmssDelugeWebConfWrite($webConfPath, $original['meta'], $original['config']);
            return '';
        }
        return $newPassword;
    }
}
