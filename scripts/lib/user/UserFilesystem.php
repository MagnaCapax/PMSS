<?php
/**
 * Helpers for enumerating users from filesystem and passwd database.
 */

class UserFilesystem
{
    /**
     * Enumerate home directory names living directly under /home.
     */
    public static function listHomeDirectories(): array
    {
        $users      = [];
        $filterList = self::homeFilterList();
        $homeDir    = '/home';

        if ($directory = @opendir($homeDir)) {
            try {
                while (false !== ($entry = readdir($directory))) {
                    if ($entry === '.' || $entry === '..' || strpos($entry, 'backup-') === 0 || strpos($entry, 'root-backup-') === 0) {
                        continue;
                    }
                    if (in_array($entry, $filterList, true)) {
                        continue;
                    }
                    $path = $homeDir.'/'.$entry;
                    if (is_dir($path)) {
                        $users[$entry] = true;
                    }
                }
            } finally {
                closedir($directory);
            }
        }

        $names = array_keys($users);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /**
     * Enumerate users from /etc/passwd whose home directories live under /home.
     */
    public static function listPasswdUsers(): array
    {
        $names = [];
        $lines = @file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $filterList = self::homeFilterList();
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            if (count($parts) < 7) {
                continue;
            }
            $name = $parts[0];
            $home = $parts[5];
            if (strpos($home, '/home/') !== 0) {
                continue;
            }
            if (in_array($name, $filterList, true) || strpos($name, 'root-backup-') === 0) {
                continue;
            }
            $names[$name] = true;
        }
        $result = array_keys($names);
        sort($result, SORT_NATURAL | SORT_FLAG_CASE);
        return $result;
    }

    /**
     * Combined list of users discovered from filesystem and passwd data.
     */
    public static function listHomeUsers(): array
    {
        $combined = array_fill_keys(self::listHomeDirectories(), true);
        foreach (self::listPasswdUsers() as $user) {
            $combined[$user] = true;
        }
        $names = array_keys($combined);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /**
     * Ignore service accounts that should not appear in tenant listings.
     */
    private static function homeFilterList(): array
    {
        return ['aquota.user', 'aquota.group', 'lost+found', 'ftp', 'srvadmin', 'srvapi', 'pmcseed', 'pmcdn', 'srvmgmt'];
    }
}
