<?php
/**
 * Permission refresh routines for user environments.
 */

require_once __DIR__.'/utils.php';

function pmssUserRefreshPermissions(array $ctx): void
{
    $user    = $ctx['user'];
    $userEsc = $ctx['user_esc'];
    $home    = $ctx['home'];

    runUserStep($user, 'Refreshing user permissions', sprintf('/scripts/util/userPermissions.php %s', $userEsc));

    $rcCustomPath = "{$home}/.rtorrent.rc.custom";
    if (file_exists($rcCustomPath)) {
        $rcCustomSha = sha1((string)file_get_contents($rcCustomPath));
        if ($rcCustomSha === 'dcf21704d49910d1670b3fdd04b37e640b755889' ||
            $rcCustomSha === 'dd10dc08de4cc9a55f554d98bc0ee8c85666b63a') {
            $skelRcCustomArg = pmssUserSkelCommandArg('.rtorrent.rc.custom');
            runUserStep(
                $user,
                'Updating .rtorrent.rc.custom from skeleton',
                sprintf('cp %s %s/', $skelRcCustomArg, escapeshellarg($home))
            );
        }
    }
}
