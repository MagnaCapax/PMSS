<?php
/**
 * ~/.virsilipasKey: the account's AI credits key file (ADR 0090), written by the platform's key distribution.
 *
 * The file is ADR-0046 Customer-editable: the customer may change or delete it. These helpers run AS THE
 * ACCOUNT USER (setVirsilipasKey.php drops root first), so a planted symlink can only ever point the user's
 * own write at the user's own files. Rules that keep supported customer changes:
 *   - a file whose endpoint= is not ours is the customer's own setting: kept;
 *   - update-only mode never creates the file, so a customer who deleted it does not get it back on rotation;
 *   - a symlink or a non-regular file at the path is never replaced.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const PMSS_VIRSILIPAS_KEY_FILE = '.virsilipasKey';
const PMSS_VIRSILIPAS_ENDPOINT = 'https://virsilipas.mcx.fi/v1';
const PMSS_VIRSILIPAS_KEY_MAX_READ = 4096;

function pmssVirsilipasKeyIsValid(string $key): bool
{
    return preg_match('/^vsk-[A-Za-z0-9_-]{16,128}$/D', $key) === 1;
}

function pmssVirsilipasKeyContent(string $key): string
{
    return 'endpoint='.PMSS_VIRSILIPAS_ENDPOINT."\nkey=".$key."\n";
}

/**
 * Is this existing file content ours to replace? Ours = endpoint= is our endpoint, or there is no endpoint=
 * line and the key= line holds a key of our shape (a hand-made file from the client-area instructions).
 */
function pmssVirsilipasKeyContentIsOurs(string $content): bool
{
    $endpoint = null;
    $key = null;
    foreach (preg_split('/\r?\n/', $content) as $line) {
        $line = trim($line);
        if (strncmp($line, 'endpoint=', 9) === 0 && $endpoint === null) {
            $endpoint = trim(substr($line, 9));
        } elseif (strncmp($line, 'key=', 4) === 0 && $key === null) {
            $key = trim(substr($line, 4));
        }
    }
    if ($endpoint !== null) {
        return rtrim($endpoint, '/') === PMSS_VIRSILIPAS_ENDPOINT;
    }
    return $key !== null && pmssVirsilipasKeyIsValid($key);
}

/**
 * Put $key into $home/.virsilipasKey. Returns the result word:
 *   written | equal | kept-own-endpoint | no-file (update-only, file absent) | symlink | not-regular
 *   | unreadable | write-failed
 */
function pmssVirsilipasKeyApply(string $home, string $key, bool $create): string
{
    $path = $home.'/'.PMSS_VIRSILIPAS_KEY_FILE;
    $wanted = pmssVirsilipasKeyContent($key);

    clearstatcache(true, $path);
    if (is_link($path)) {
        return 'symlink';
    }
    if (file_exists($path)) {
        if (!is_file($path)) {
            return 'not-regular';
        }
        $current = @file_get_contents($path, false, null, 0, PMSS_VIRSILIPAS_KEY_MAX_READ);
        if ($current === false) {
            return 'unreadable';
        }
        if ($current === $wanted) {
            return 'equal';
        }
        if (!pmssVirsilipasKeyContentIsOurs($current)) {
            return 'kept-own-endpoint';
        }
    } elseif (!$create) {
        return 'no-file';
    }

    // Temp file in the same directory + rename: the rename replaces whatever is at the path, never follows it.
    $old = umask(077);
    $tmp = @tempnam($home, '.virsilipasKey.');
    umask($old);
    if ($tmp === false || dirname($tmp) !== $home) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        return 'write-failed';
    }
    $ok = @chmod($tmp, 0600) && @file_put_contents($tmp, $wanted) === strlen($wanted) && @rename($tmp, $path);
    if (!$ok) {
        @unlink($tmp);
        return 'write-failed';
    }
    return 'written';
}
