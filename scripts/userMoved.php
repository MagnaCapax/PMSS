#!/usr/bin/env php
<?php
/** Park a verified migration's old copy while retaining SSH/SFTP access. */

require_once __DIR__.'/lib/user/moved.php';

try {
    [$username, $until] = pmssUserMovedParseArguments($argv);
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        throw new RuntimeException('userMoved.php must run as root');
    }
    if (pmssPasswdEntryPositiveUid(pmssUserAccountLookup($username)) === null) {
        throw new RuntimeException('User does not exist or is not a customer account');
    }
} catch (Exception $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}

exit(pmssUserMovedApply($username, $until, new UserConfigStore()));
