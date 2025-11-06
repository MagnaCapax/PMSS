<?php
/**
 * High-level user repository wrapper preserving legacy helpers.
 */

require_once __DIR__.'/user/UserRepository.php';
require_once __DIR__.'/user/UserFilesystem.php';

class users extends UserRepository
{
    /**
     * Cached copy of the user database for quick access.
     */
    /** @var array */
    private $users = [];

    public function __construct()
    {
        parent::__construct();
        $this->refreshUsers();
        $this->pruneCache();
    }

    public function __destruct()
    {
        parent::persist();
    }

    /**
     * Return the user metadata array loaded from the repository.
     */
    public function getUsers(): array
    {
        return $this->users;
    }

    /**
     * Add a new user and persist if the payload validates.
     */
    public function addUser(string $username, array $data): void
    {
        if ($this->set($username, $data)) {
            $this->syncCache(true);
        }
    }

    /**
     * Update an existing user and refresh the cached view.
     */
    public function modifyUser(string $username, array $data): bool
    {
        $result = $this->set($username, $data);
        if ($result) {
            $this->syncCache();
        }
        return $result;
    }

    /**
     * Remove a user entry and persist the change immediately.
     */
    public function removeUser(string $username): void
    {
        parent::remove($username);
        $this->syncCache(true);
    }

    /**
     * Remove stale database entries and return the number purged.
     */
    public function prune(): int
    {
        $before = count($this->users);
        parent::pruneStaleEntries();
        $this->refreshUsers();
        return max(0, $before - count($this->users));
    }

    /**
     * List user directories directly under /home.
     */
    public static function listHomeDirectories(): array
    {
        return UserFilesystem::listHomeDirectories();
    }

    /**
     * List named users from /etc/passwd whose homes live under /home.
     */
    public static function listPasswdUsers(): array
    {
        return UserFilesystem::listPasswdUsers();
    }

    /**
     * Combined list of filesystem and passwd-based user accounts.
     */
    public static function listHomeUsers(): array
    {
        return UserFilesystem::listHomeUsers();
    }

    /**
     * Reload the cached user data from the repository backend.
     */
    private function refreshUsers(): void
    {
        $this->users = $this->all();
    }

    /**
     * Refresh the cache and optionally persist pending database updates.
     */
    private function syncCache(bool $persist = false): void
    {
        $this->refreshUsers();
        if ($persist) {
            parent::persist();
        }
    }

    /**
     * Prune stale cache entries during construction.
     */
    private function pruneCache(): void
    {
        if (empty($this->users)) {
            return;
        }
        parent::pruneStaleEntries();
        $this->refreshUsers();
    }
}
