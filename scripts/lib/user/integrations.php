<?php
/**
 * Helper utilities for optional integrations such as rclone.
 */

require_once __DIR__.'/helpers.php';

/**
 * Ensure helper ports and directories exist for rclone integrations.
 */
function userEnsureRclonePort(array $user): void
{
    $rclonePortFile = sprintf('/home/%s/.rclonePort', $user['name']);
    if (!file_exists($rclonePortFile)) {
        file_put_contents($rclonePortFile, (int) round(rand(1500, 65500)));
    }
}
