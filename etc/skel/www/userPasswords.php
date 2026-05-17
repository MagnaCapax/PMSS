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
