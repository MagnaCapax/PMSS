<?php
/**
 * Shared bootstrap helpers for app installers.
 *
 * Centralises runtime inclusion so app installers do not duplicate the same
 * include/error handling logic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!function_exists('pmssUpdateAppRuntimeBootstrap')) {
    /**
     * Ensure updater runtime helpers are loaded for app installers.
     *
     * @param string $appName Human-readable application name used in warnings.
     */
    function pmssUpdateAppRuntimeBootstrap(string $appName): bool
    {
        $runtimePath = dirname(__DIR__).'/runtime.php';
        if (!@include_once $runtimePath) {
            $message = sprintf('%s updater: missing runtime helper at %s, skipping install.', $appName, $runtimePath);
            if (defined('STDERR')) {
                fwrite(STDERR, $message."\n");
            }
            return false;
        }

        return true;
    }
}

