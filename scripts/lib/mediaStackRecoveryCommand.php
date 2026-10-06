<?php
/**
 * Root-side bridge to the root-owned skeleton helper. Never load a user's www copy.
 * Customer PHP loads its own bundled copy from the same source file.
 */
$helper = dirname(__DIR__, 2).'/etc/skel/www/mediaStackRecoveryCommand.php';
if (!is_file($helper) || is_link($helper)) {
    throw new RuntimeException('Media-stack helper unavailable');
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0
    && (fileowner($helper) !== 0 || (fileperms($helper) & 0022) !== 0
        || fileowner(dirname($helper)) !== 0 || (fileperms(dirname($helper)) & 0022) !== 0)) {
    throw new RuntimeException('Unsafe media-stack helper ownership');
}
require_once $helper;
