<?php
/**
 * Mediainfo installer helper.
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../logging.php';

if (!function_exists('pmssInstallMediaInfo')) {
    /**
     * Install MediaArea tools (mediainfo, mediaconch, qctools, qcli) via apt.
     * Simple, idempotent: apt handles already-installed packages.
     */
    function pmssInstallMediaInfo(string $lsbCodename, ?callable $logger = null): void
    {
        $pkgs = ['mediainfo', 'mediaconch', 'qctools', 'qcli'];
        $pkgArgs = implode(' ', array_map('escapeshellarg', $pkgs));
        $rc = runStep('Installing MediaArea tools', aptCmd('install -y '.$pkgArgs));
        if ($rc !== 0) {
            runStep('Attempting apt fix-broken for MediaArea tools', aptCmd('--fix-broken install -y'));
            runStep('Re-attempting MediaArea tools install', aptCmd('install -y '.$pkgArgs));
        }
    }
}
