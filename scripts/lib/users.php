<?php
/**
 * High-level user repository wrapper preserving legacy helpers.
 *
 * This facade keeps a cached in-memory view of the user database while
 * exposing convenience methods that mirror historical helpers. It delegates
 * persistence and filesystem concerns to specialised classes so callers can
 * reason about users at a higher level.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/user/userRepository.php';
require_once __DIR__.'/user/userFilesystem.php';

class users extends UserRepository
{
    /**
     * Cached copy of the user database for quick access.
     */
    /** @var array */
    private $users = [];

    /**
     * Initialise the repository and eagerly populate the in-memory cache.
     *
     * The constructor loads all known users from the underlying repository and
     * immediately prunes stale entries to keep the cached view consistent
     * with the on-disk representation.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->users = $this->all();
        if (!empty($this->users)) {
            parent::pruneStaleEntries();
            $this->users = $this->all();
        }
    }

    /**
     * Persist any pending repository changes when the wrapper is destroyed.
     *
     * Destruction mirrors legacy behaviour by forcing a flush of the backing
     * repository so callers do not have to remember to save explicitly.
     *
     * @return void
     */
    public function __destruct()
    {
        parent::persist();
    }

    /**
     * Return the user metadata array loaded from the repository.
     *
     * The returned structure mirrors the underlying repository format and is
     * served from an in-memory cache to keep repeated lookups inexpensive.
     *
     * @return array Indexed array of user metadata entries.
     */
    public function getUsers(): array
    {
        return $this->users;
    }

    /**
     * Add a new user and persist if the payload validates.
     *
     * Delegates validation and storage to the base repository and refreshes
     * the cached view on success so subsequent calls see the new user data.
     *
     * @param string $username Username to create in the repository.
     * @param array  $data     Metadata payload describing the new user.
     *
     * @return void
     */
    public function addUser(string $username, array $data): void
    {
        if ($this->set($username, $data)) {
            $this->users = $this->all();
            parent::persist();
        }
    }

    /**
     * Update an existing user and refresh the cached view.
     *
     * Passes the payload to the repository, then refreshes the cached copy
     * when the update succeeds so callers always see a coherent snapshot.
     *
     * @param string $username Username whose metadata should be updated.
     * @param array  $data     Replacement metadata payload for the user.
     *
     * @return bool True when the user was updated, false otherwise.
     */
    public function modifyUser(string $username, array $data): bool
    {
        $result = $this->set($username, $data);
        if ($result) {
            $this->users = $this->all();
        }
        return $result;
    }

    /**
     * Remove a user entry and persist the change immediately.
     *
     * Invokes the base repository removal logic and ensures the in-memory
     * cache and underlying storage are kept in sync after the deletion.
     *
     * @param string $username Username to remove from the repository.
     *
     * @return void
     */
    public function removeUser(string $username): void
    {
        parent::remove($username);
        $this->users = $this->all();
        parent::persist();
    }

    /**
     * Remove stale database entries and return the number purged.
     *
     * Calls into the repository’s pruning helper, reloads the cached view,
     * and reports how many entries disappeared as a result of the clean-up.
     *
     * @return int Number of user records removed during pruning.
     */
    public function prune(): int
    {
        $before = count($this->users);
        parent::pruneStaleEntries();
        $this->users = $this->all();
        return max(0, $before - count($this->users));
    }

    /**
     * List user directories directly under /home.
     */
    public static function listHomeDirectories(): array
    {
        return userFilesystem::listHomeDirectories();
    }

    /**
     * List named users from /etc/passwd whose homes live under /home.
     */
    public static function listPasswdUsers(): array
    {
        return userFilesystem::listPasswdUsers();
    }

    /**
     * Combined list of filesystem and passwd-based user accounts.
     */
    public static function listHomeUsers(): array
    {
        return userFilesystem::listHomeUsers();
    }

}
