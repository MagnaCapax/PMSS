<?php
/**
 * User discovery and validation helpers for resource stats processing.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

class ResourceStatsUserScope
{
    /** @var string */
    private $resourceDir;
    /** @var string */
    private $homeDir;
    /** @var string */
    private $passwdFile;

    public function __construct(string $resourceDir, string $homeDir, string $passwdFile)
    {
        $this->resourceDir = rtrim($resourceDir, '/');
        $this->homeDir = rtrim($homeDir, '/');
        $this->passwdFile = $passwdFile;
    }

    /** Discover users by scanning the resource log directory. */
    public function discoverUsers(): array
    {
        $users = array_map('basename', array_filter(glob($this->resourceDir.'/*'), 'is_file'));
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        return $users;
    }

    /** Launch detached workers for each user to process in parallel. */
    public function spawnWorkers(string $scriptPath, array $users): void
    {
        $script = escapeshellarg($scriptPath);
        foreach ($users as $user) {
            $userArg = escapeshellarg($user);
            $command = "nohup {$script} {$userArg} >> /var/log/pmss/resourceStats.log 2>&1 &";
            passthru($command);
        }
    }

    /** Sanitize user input by stripping unexpected characters. */
    public function sanitizeUser(string $input): string
    {
        return preg_replace('/[^a-zA-Z0-9-_]/', '', $input);
    }

    /** Validate that a user has resource data and a home directory. */
    public function validateUser(string $username): bool
    {
        $path = $this->resourceDir.'/'.$username;
        $homePath = $this->homeDir.'/'.$username;
        return is_readable($path)
            && $this->userExistsInPasswd($username)
            && is_dir($homePath);
    }

    private function userExistsInPasswd(string $username): bool
    {
        $passwd = @file_get_contents($this->passwdFile);
        if ($passwd === false) {
            return false;
        }
        return preg_match('/^'.preg_quote($username, '/').':/m', $passwd) === 1;
    }
}
