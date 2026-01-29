<?php
/**
 * Root shell baseline setup for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/logging.php';
require_once dirname(__DIR__, 2).'/runtime.php';

if (!function_exists('pmssConfigureRootShellDefaults')) {
    /**
     * Ensure root shell defaults mirror the historical installer behaviour.
     */
    function pmssConfigureRootShellDefaults(?callable $logger = null): void
    {
        $log    = pmssSelectLogger($logger);
        $bashrc = '/root/.bashrc';
        $lines  = file_exists($bashrc) ? file($bashrc, FILE_IGNORE_NEW_LINES) : [];
        if ($lines === false) {
            $lines = [];
        }

        $updates = [];
        $alias   = "alias ls='ls --color=auto'";
        $pathAdd = 'PATH=$PATH:/scripts';

        if (!in_array($alias, $lines, true)) {
            $lines[]   = $alias;
            $updates[] = $alias;
        }
        if (!in_array($pathAdd, $lines, true)) {
            $lines[]   = $pathAdd;
            $updates[] = $pathAdd;
        }

        if ($updates === []) {
            $log('[SKIP] Root shell defaults already configured');
            return;
        }

        @file_put_contents($bashrc, implode(PHP_EOL, $lines).PHP_EOL);
        $log('Appended root shell defaults: '.implode(', ', $updates));
    }
}

