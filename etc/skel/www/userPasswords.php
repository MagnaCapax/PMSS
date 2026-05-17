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
 * touches service credential state; customers see their current service
 * password but do not write auth or web.conf from the panel.
 *
 * @license GPL-3.0-only
 */

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
