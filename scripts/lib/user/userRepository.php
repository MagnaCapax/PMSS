<?php
/**
 * Lightweight wrapper providing persistence for user metadata.
 */

// Note: file name and class name are intentionally lowercased
// (`userFilesystem.php` / `userFilesystem`) to match autoloader
// expectations on case-sensitive filesystems.
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/userFilesystem.php';
require_once __DIR__.'/UserValidator.php';
require_once __DIR__.'/UserChecksum.php';

class UserRepository
{
    public const USERS_DB_FILE  = '/etc/seedbox/runtime/users.json';
    public const SCHEMA_VERSION = 1;

    /** @var array */
    private $users = [];

    public function __construct()
    {
        $this->users = $this->loadUsers();
    }

    public function __destruct()
    {
        $this->persist();
    }

    public function all(): array
    {
        return $this->users;
    }

    public function get(string $username): ?array
    {
        return $this->users[$username] ?? null;
    }

    public function set(string $username, array $payload): bool
    {
        if (!UserValidator::isValidUsername($username) || !UserValidator::validatePayload($payload)) {
            return false;
        }
        $this->users[$username] = UserValidator::normalisedPayload($payload);
        return true;
    }

    public function remove(string $username): void
    {
        unset($this->users[$username]);
    }

    public function persist(): bool
    {
        $path = $this->usersDbPath();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $payload = [
            'schema'       => self::SCHEMA_VERSION,
            'generated_at' => date('c'),
            'users'        => $this->users,
        ];
        $payload['checksum'] = UserChecksum::checksum($payload['users']);

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            error_log('UserRepository: Failed to encode user database to JSON.');
            return false;
        }
        if (@file_put_contents($path, $encoded) === false) {
            error_log('UserRepository: Failed to write user database file.');
            return false;
        }
        @chmod($path, 0640);
        return true;
    }

    public function pruneStaleEntries(): void
    {
        $homeUsers = userFilesystem::listHomeUsers();
        $changed   = false;
        foreach (array_keys($this->users) as $username) {
            if (!in_array($username, $homeUsers, true)) {
                unset($this->users[$username]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->persist();
        }
    }

    private function loadUsers(): array
    {
        $path = $this->usersDbPath();
        if (!file_exists($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['users']) || !is_array($data['users'])) {
            error_log('UserRepository: Invalid JSON user database.');
            return [];
        }

        if (isset($data['checksum'])) {
            $expected = UserChecksum::checksum($data['users']);
            if (!hash_equals($expected, (string)$data['checksum'])) {
                error_log('UserRepository: User database checksum mismatch.');
            }
        }

        return $data['users'];
    }

    private function usersDbPath(): string
    {
        return pmssResolvePathFromEnv('PMSS_USERS_DB_FILE', self::USERS_DB_FILE);
    }
}
