<?php
/**
 * Root shell baseline setup for update-step2 system preparation.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__).'/logging.php';

    /**
     * Ensure root shell defaults mirror the historical installer behaviour.
     */
    function pmssConfigureRootShellDefaults(?callable $logger = null): void
    {
        $log    = pmssSelectLogger($logger);
        $bashrc = '/root/.bashrc';
        $lines = file_exists($bashrc) ? (file($bashrc, FILE_IGNORE_NEW_LINES) ?: []) : [];

        $defaults = [
            "alias ls='ls --color=auto'",
            'PATH=$PATH:/scripts',
        ];
        if (($missing = array_diff($defaults, $lines)) === []) {
            $log('[SKIP] Root shell defaults already configured');
            return;
        }

        @file_put_contents($bashrc, implode(PHP_EOL, array_merge($lines, $missing)).PHP_EOL);
        $log('Appended root shell defaults: '.implode(', ', $missing));
    }
