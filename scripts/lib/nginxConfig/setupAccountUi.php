<?php
/** Retire the former account UI default listeners during normal nginx setup. */
function pmssNginxConfigSetupAccountUi(bool $subdomainEnabled, string $nginxSslBlock, string $subdomainConfigDir): void
{
    foreach (['pmss-pikkumies-default.conf', 'pmss-pikkumies-hermes-default.conf', 'pmss-hermes-default.conf'] as $name) {
        pmssCreateNginxConfigSetupRemoveDefault($subdomainConfigDir.'/'.$name);
    }
    // Retire only the root-owned directory with the former PMSS page inventory.
    $directory = '/var/www/pikkumies';
    if (!pmssPathTargetIsSafe($directory, true, false, false) || is_link($directory)
        || !is_dir($directory) || fileowner($directory) !== 0 || (fileperms($directory) & 0022) !== 0) return;
    $entries = @scandir($directory);
    if (!is_array($entries)) return;
    $pages = array_values(array_diff($entries, ['.', '..']));
    if (array_diff($pages, ['bar.html', 'not-running.html', 'hermes-login.html']) !== []) return;
    foreach ($pages as $page) {
        $path = $directory.'/'.$page;
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['nlink'] !== 1 || $stat['uid'] !== 0) return;
    }
    foreach ($pages as $page) pmssCreateNginxConfigSetupRemoveDefault($directory.'/'.$page);
    if (!@rmdir($directory)) pmssCreateNginxConfigSetupWarn('remove obsolete account UI pages', $directory);
}
