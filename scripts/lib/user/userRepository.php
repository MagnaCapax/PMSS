<?php
/**
 * User repository backed by the per-user config store.
 *
 * This wrapper preserves the historical `UserRepository` API (all/get/set/remove)
 * while moving the durable storage to:
 *   /etc/seedbox/config/users/<username>.json
 */

require_once __DIR__.'/userFilesystem.php';
require_once __DIR__.'/userConfigStore.php';

class UserRepository
{
    /** @var UserConfigStore */
    private $store;

    public function __construct()
    {
        $this->store = new UserConfigStore();
    }

    public function __destruct()
    {
        $this->persist();
    }

    public function all(): array
    {
        return $this->store->loadAll();
    }

    public function get(string $username): ?array
    {
        return $this->store->get($username);
    }

    public function set(string $username, array $payload): bool
    {
        return $this->store->set($username, $payload);
    }

    public function remove(string $username): void
    {
        $this->store->remove($username);
    }

    /**
     * Back-compat no-op; per-user writes are immediate.
     */
    public function persist(): bool
    {
        return true;
    }

    public function pruneStaleEntries(): void
    {
        $homeUsers = userFilesystem::listHomeUsers();
        $users = $this->store->loadAll();
        foreach (array_keys($users) as $username) {
            if (!in_array($username, $homeUsers, true)) {
                $this->store->remove($username);
            }
        }
    }
}

