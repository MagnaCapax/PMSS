<?php
/** Atomic private account-file write, shared by Configure and the Transmission launcher CLI. */

if (!function_exists('pmssConfigureFileWrite')) {
/** Replace one account file atomically, with private permissions. */
function pmssConfigureFileWrite($path, $value)
{
    $dir = dirname($path);
    if (is_link($path) || (file_exists($path) && !is_file($path)) || !is_dir($dir)) return false;
    $tmp = @tempnam($dir, '.pmss-configure-');
    if (!is_string($tmp)) return false;
    $private = @chmod($tmp, 0600);
    $ok = $private && @file_put_contents($tmp, $value, LOCK_EX) === strlen($value)
        && !is_link($path) && @rename($tmp, $path);
    if (!$ok) @unlink($tmp);
    return $ok;
}
}
