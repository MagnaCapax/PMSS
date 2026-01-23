<?php
/**
 * Miscellaneous security tweaks applied post-update.
 */

require_once __DIR__.'/../runtime/commands.php';

if (!function_exists('pmssEnsureTestfile')) {
    /**
     * Ensure the standard download speed test file exists.
     * #TODO(testfile): consider templating/host-wide CDN instead of local 100MiB dd.
     */
    function pmssEnsureTestfile(): void
    {
        $path = '/var/www/testfile';
        if (file_exists($path) && filesize($path) === 104857600) {
            return;
        }
        $command = 'dd if=/dev/urandom of='.$path.' bs=1M count=100 status=none';
        runStep('Generating /var/www/testfile sample', $command);
    }
}

