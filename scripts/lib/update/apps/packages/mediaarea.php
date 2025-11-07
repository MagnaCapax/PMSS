<?php
/**
 * Queue MediaArea CLI tools for installation via the central package phase.
 * We do not install GUI packages; CLI only.
 */

require_once __DIR__.'/../packages/helpers.php';

function pmssInstallMediaAreaTools(int $distroVersion): void
{
    // CLI-only: mediainfo (CLI), mediaconch (CLI), qcli (CLI). No GUI packages.
    pmssQueuePackages(['mediainfo', 'mediaconch', 'qcli']);
}

