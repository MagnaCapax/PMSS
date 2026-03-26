<?php
/**
 * Helpers for enumerating users from filesystem and passwd database.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/userLifecycle.php';

class userFilesystem
{
    public static function homePath(string $username, string $homeRoot = '/home'): string
    {
        return rtrim($homeRoot, '/').'/'.$username;
    }

    /** @return array{username:string,homeDir:string} */
    public static function requireCliUserHome(
        string $rawUsername,
        string $action,
        string $errorFormat,
        string $missingMessage,
        string $logMessage = 'Rejected username due to validation failure'
    ): array {
        $username = pmssRequireCliUsername($rawUsername, $action, $errorFormat, $logMessage);
        $homeDir = self::homePath($username);
        if (!is_dir($homeDir)) die(strpos($missingMessage, '%s') === false ? $missingMessage : sprintf($missingMessage, $homeDir));
        return ['username' => $username, 'homeDir' => $homeDir];
    }

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
                    // Skip dot files and backup directories
                    if ($entry === '.' || $entry === '..' || strpos($entry, 'backup-') === 0 || strpos($entry, 'root-backup-') === 0 || in_array($entry, $filterList, true)) {
                        continue;
                    }
                    $path = $homeDir.'/'.$entry;
                    if (is_dir($path)) {
                        // Skip symlinks or root-owned paths to avoid transient mounts/special homes
                        if (@is_link($path)) {
                            continue;
                        }
                        $owner = @fileowner($path);
                        if ($owner === 0) { // root-owned home dir often indicates special/system use
                            continue;
                        }
                        $users[$entry] = true;
                    }
                }
            } finally {
                closedir($directory);
            }
        }

        return self::sortUsernames(array_keys($users));
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
        return self::sortUsernames(array_keys($names));
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
        // Filter out users that do not exist in the system user database
        $valid = [];
        foreach (array_keys($combined) as $name) {
            if (posix_getpwnam($name) !== false) {
                $valid[] = $name;
            }
        }
        return self::sortUsernames($valid);
    }

    public static function withAdditionalUsers(array $users, array $additionalUsers): array
    {
        foreach ($users === [] ? [] : $additionalUsers as $additionalUser) {
            $additionalUser = trim((string) $additionalUser);
            $additionalUser !== '' && pmssNormalizeUsername($additionalUser) === $additionalUser && preg_match('/^[a-z0-9-]+$/', $additionalUser) === 1 && $users[] = $additionalUser;
        }
        return array_values(array_unique($users));
    }

    /** Keep user listings in one natural, case-insensitive order. */
    private static function sortUsernames(array $names): array
    {
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
