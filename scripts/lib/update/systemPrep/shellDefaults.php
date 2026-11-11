<?php
/** Root shell baseline convergence; loaded by systemPrep.php. @license GPL-3.0-only */

/**
 * Ensure root shell defaults mirror the historical installer behaviour.
 */
function pmssConfigureRootShellDefaults(?callable $logger = null, ?string $bashrc = null): void
{
    $log    = $logger ?: 'logMessage';
    $bashrc = $bashrc ?? '/root/.bashrc';
    if (!pmssManagedPathIsSafe($bashrc, 'root shell defaults', $log)) {
        return;
    }

    $lines = [];
    if (file_exists($bashrc)) {
        $lines = @file($bashrc, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            $log('[WARN] Unable to read root shell defaults at '.$bashrc);
            return;
        }
    }

    $defaults = [
        "alias ls='ls --color=auto'",
        'PATH=$PATH:/scripts',
    ];
    if (($missing = array_diff($defaults, $lines)) === []) {
        $log('[SKIP] Root shell defaults already configured');
        return;
    }

    $contents = implode(PHP_EOL, array_merge($lines, $missing)).PHP_EOL;
    // Keep existing metadata and never report success after a short or failed write.
    if (!pmssReplaceUserFilePreservingMetadata($bashrc, $contents)) {
        $log('[WARN] Unable to write root shell defaults at '.$bashrc);
        return;
    }
    $log('Appended root shell defaults: '.implode(', ', $missing));
}
