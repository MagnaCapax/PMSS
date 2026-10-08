<?php
/** Build the one-shot media-stack recovery command for both panel and root launcher. */
function pmssMediaStackPanelRecoveryCommandBuild(string $home, string $username): string
{
    $scriptPath = rtrim($home, '/').'/install-media-stack.sh';
    $successMarker = 'pmss-media-stack-started';

    return 'cd '.escapeshellarg($home)
        .' && HOME='.escapeshellarg($home)
        .' USER='.escapeshellarg($username)
        .' LOGNAME='.escapeshellarg($username)
        .' /bin/bash '.escapeshellarg($scriptPath).' --start-stopped >/dev/null 2>&1'
        .' && printf %s '.escapeshellarg($successMarker);
}
