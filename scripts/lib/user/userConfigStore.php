<?php
/**
 * Per-user config store (durable).
 *
 * Canonical storage (per user, one file):
 *   /etc/seedbox/config/users/<username>.json
 *
 * Legacy fallback (read-only; used only when canonical is missing):
 *   /etc/seedbox/runtime/users.json
 *
 * Payload is a simple associative array. Unknown keys are preserved.
 *
 * This store, not any file under /home, holds the per-user settings root jobs act on
 * (ADR 0213). VALUE_KEYS are set one at a time by the provisioning CLIs through
 * setValue(); a whole-record set() keeps their stored values.
 *
 * Known keys are documented for operators in the user-config CLI/resource
 * helpers. Feature policy helpers are loaded below from userConfigPolicy.php.
 *
 * #TODO(Q4/2027): Remove legacy /etc/seedbox/runtime/users.json fallback.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

pmssRequireRelativeFiles(__DIR__, [
    'UserValidator.php', 'billingIds.php', 'notificationEmail.php',
    'userConfigPayload.php', 'userConfigPersistence.php',
    '../lighttpd/userFileWrite.php', '../systemdSliceProperties.php',
]);

class UserConfigStore
{
    /** Values the provisioning CLIs set one at a time (writeHomeMarker.php, userBonusTraffic.php). */
    public const VALUE_KEYS = ['bonusPercent', 'bonusTrafficGiB'];

    /** @var string */
    private $userDir;
    private $legacyAggregatePath;

    public function __construct(?string $configDir = null)
    {
        $configDir = rtrim($configDir ?: pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config'), '/');
        $this->userDir = $configDir.'/users';
        // Legacy file lives alongside the config directory under /etc/seedbox/runtime.
        // Derive it from the config dir so tests can point at a temp tree.
        $this->legacyAggregatePath = rtrim(dirname($configDir), '/').'/runtime/users.json';
    }

    public function get(string $username): ?array
    {
        if (($username = $this->validatedUsername($username)) === null) {
            return null;
        }

        $payload = pmssJsonFileReadAssoc($this->userConfigPath($username));
        return is_array($payload) ? pmssUserConfigPayloadNormalise($payload) : (pmssUserConfigLegacyUsersRead($this->legacyAggregatePath)[$username] ?? null);
    }

    public function set(string $username, array $payload): bool
    {
        return $this->update($username, static function (?array $current) use ($payload): array {
            foreach (self::VALUE_KEYS as $key) {
                unset($payload[$key]);
                if (is_array($current) && array_key_exists($key, $current)) $payload[$key] = $current[$key];
            }
            return $payload;
        });
    }

    /** Set one VALUE_KEYS or billing value (null removes it) in an existing record, under the per-user lock. */
    public function setValue(string $username, string $key, $value): bool
    {
        if (!in_array($key, array_merge(self::VALUE_KEYS, ['billingServiceId', 'billingClientId']), true)) return false;
        return $this->update($username, static function (?array $current) use ($key, $value): ?array {
            if (!is_array($current)) return null;
            if ($value === null) unset($current[$key]);
            else $current[$key] = $value;
            return $current;
        });
    }

    /** Read-modify-write one record under the per-user lock; $mutate returns the new payload or null. */
    private function update(string $username, callable $mutate): bool
    {
        if (($username = $this->validatedUsername($username)) === null) {
            return false;
        }
        try {
            $lock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-user-config-'.$username.'.lock'));
        } catch (RuntimeException $exception) {
            $lock = false;
        }
        if ($lock === false) {
            error_log('UserConfigStore: lock unavailable for '.$username);
            return false;
        }
        try {
            $payload = $mutate($this->get($username));
            if (!is_array($payload)) return false;
            $payload = pmssUserConfigPayloadNormalise($payload);
            foreach (UserValidator::REQUIRED_FIELDS as $key) {
                if (!array_key_exists($key, $payload) || !is_numeric($payload[$key])) {
                    error_log('UserConfigStore: refusing to write invalid payload for '.$username);
                    return false;
                }
            }
            return pmssUserConfigJsonWrite($this->userConfigPath($username), $payload, 0640, 'root', 'root');
        } finally {
            pmssLockHandleRelease($lock);
        }
    }

    public function remove(string $username): bool
    {
        if (($username = $this->validatedUsername($username)) === null) {
            return false;
        }
        $path = $this->userConfigPath($username);
        return !is_file($path) || is_link($path) || @unlink($path);
    }

    public function loadAll(): array
    {
        $users = array_replace(pmssUserConfigLegacyUsersRead($this->legacyAggregatePath), pmssUserConfigCanonicalUsersRead($this->userDir));

        ksort($users, SORT_STRING);
        return $users;
    }

    public function setSuspended(string $username, bool $suspended): bool
    {
        if (($username = $this->validatedUsername($username)) === null
            || !is_array($payload = $this->get($username))) {
            return false;
        }
        $payload['suspended'] = $suspended;
        return $this->persist($username, $payload);
    }

    /**
     * Update moved state only in the root-owned store, without a home cache write.
     * @param string $username Existing account name.
     * @param string|null $until Valid removal date, or null to clear.
     * @return bool Whether the canonical update succeeded.
     */
    public function setMovedUntil(string $username, ?string $until): bool
    {
        if (($until !== null && !pmssUserMovedDateIsValid($until))
            || !is_array($payload = $this->get($username))) return false;
        if ($until === null) unset($payload['movedUntil']);
        else $payload['movedUntil'] = $until;
        return $this->set($username, $payload);
    }

    public function persist(string $username, array $payload): bool
    {
        if (!$this->set($username, $payload)) {
            return false;
        }
        $this->writeUserCache($username, $payload);
        return true;
    }

    public function applyFallbacks(string $username, array $payload): array
    {
        $username = $this->validatedUsername($username);
        $payload = pmssUserConfigPayloadNormalise($payload);
        if ($username !== null && empty($payload['ramMiB'])) {
            $payload['ramMiB'] = $this->resolveRamMiB($username);
        }
        // Billing IDs come only from provisioning state.
        return pmssUserConfigPayloadNormalise($payload);
    }

    private function writeUserCache(string $username, array $payload): void
    {
        if (($username = $this->validatedUsername($username)) === null) {
            return;
        }
        $home = "/home/{$username}";
        if (!is_dir($home) || is_link($home)) {
            return;
        }
        $configDir = $home.'/.config';
        if (is_link($configDir)) {
            return;
        }
        require_once __DIR__.'/directories.php';
        if (!is_dir($configDir) && !pmssEnsureUserHomeDir($username, $home, '.config', 0755)) return;

        $path = $configDir.'/pmss-user.json';
        // Root-owned, user-readable (group): cache for local tooling.
        pmssUserConfigJsonWrite($path, pmssUserConfigPayloadNormalise($payload), 0640, 'root', $username);
    }

    /**
     * Resolve RAM limit in MiB from the systemd user slice.
     */
    public function resolveRamMiB(string $username): int
    {
        if (($username = $this->validatedUsername($username)) === null || pmssTestModeEnabled()) {
            return 0;
        }
        $limits = pmssReadUserSlicePropertiesByUsername($username, ['MemoryHigh', 'MemoryMax']);
        $bytes = (int) (pmssSystemdPropertyTrailingInt($limits['MemoryMax']) ?: pmssSystemdPropertyTrailingInt($limits['MemoryHigh']) ?: 0);
        return $bytes > 0 ? max(0, (int) floor($bytes / 1048576)) : 0;
    }

    /** Read the billing-provisioned notification recipient for one user. */
    public function readNotificationEmail(string $username): ?string
    {
        $payload = $this->get($username);
        $email = is_array($payload) ? ($payload['notificationEmail'] ?? null) : null;
        return is_string($email) ? pmssUserNotificationEmailParse($email) : null;
    }

    /** One stored non-negative integer, or 0 when it is missing, invalid or above $max. */
    public function getInt(string $username, string $key, int $max = PHP_INT_MAX): int
    {
        $payload = $this->get($username);
        $value = is_array($payload) ? ($payload[$key] ?? null) : null;
        return is_int($value) && $value >= 0 && $value <= $max ? $value : 0;
    }

    private function validatedUsername(string $username): ?string
    {
        $username = pmssNormalizeUsername($username);
        return UserValidator::isValidUsername($username) ? $username : null;
    }

    private function userConfigPath(string $username): string
    {
        return $this->userDir.'/'.$username.'.json';
    }
}

require_once __DIR__.'/userConfigPolicy.php';
