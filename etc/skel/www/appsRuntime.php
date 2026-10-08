<?php
/** Live, customer-owned Apps tab probes and bounded app logs. */
require_once __DIR__.'/scriptsInc.php';
require_once __DIR__.'/userMediaStackPanel.php';

/** Guard every helper supplied by another customer file before the page runs. */
function pmssAppsRuntimeReady(): bool
{
    foreach (array('pmssActionScriptJs', 'pmssFrontendShellExec', 'pmssCustomerHomePath',
        'pmssCustomerNativePidsRead', 'pmssCustomerAppsStatusRead', 'pmssCustomerManagedAppDefinitions',
        'pmssWelcomeServiceAvailable', 'pmssMediaStackPanelAppDefinitionsRead',
        'pmssMediaStackPanelStatusRead', 'pmssMediaStackPanelExpectedAppIdsRead') as $helper) {
        if (!function_exists($helper)) return false;
    }
    return true;
}

/** Compatibility entry point for existing Apps callers. */
function pmssAppsLiveStatusRead(string $home, string $username, string $hostname): array
{
    return pmssCustomerAppsStatusRead($home, $username, $hostname);
}

/** Read only a fixed app log, cap work to 16 KiB and output to twenty short lines. */
function pmssAppsLogTailRead(string $home, string $app): string
{
    if (!in_array($app, pmssCustomerAppsMediaIdsRead(), true)) return '';
    $relative = $app === 'jellyfin' ? '.config/jellyfin/log/jellyfin.log'
        : '.config/'.$app.'/'.$app.'.log';
    $path = pmssCustomerHomePath($home, $relative);
    if (!is_file($path) || is_link($path)) return '';
    $size = @filesize($path);
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return '';
    if (is_int($size) && $size > 16384) @fseek($handle, -16384, SEEK_END);
    $raw = stream_get_contents($handle, 16384);
    @fclose($handle);
    if (!is_string($raw)) return '';
    $lines = array_slice(explode("\n", trim($raw)), -20);
    return implode("\n", array_map(static function ($line) {
        return htmlspecialchars(substr($line, 0, 300), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }, $lines));
}
