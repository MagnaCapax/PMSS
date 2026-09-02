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
 * Known keys are documented for operators in the user-config CLI/resource
 * helpers. Feature policy helpers are loaded below from userConfigPolicy.php.
 *
 * #TODO(Q4/2027): Remove legacy /etc/seedbox/runtime/users.json fallback.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

require_once __DIR__.'/UserValidator.php';
require_once __DIR__.'/billingIds.php';
require_once __DIR__.'/notificationEmail.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../systemdSliceProperties.php';

class UserConfigStore
{
    /** @var string */
    private $userDir;
    private $legacyAggregatePath;

    public function __construct(?string $configDir = null)
    {
        $configDir = rtrim($configDir ?: '/etc/seedbox/config', '/');
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
        return is_array($payload) ? $this->normalise($payload) : ($this->loadLegacyUsers()[$username] ?? null);
    }

    public function set(string $username, array $payload): bool
    {
        if (($username = $this->validatedUsername($username)) === null) {
            return false;
        }

        $payload = $this->normalise($payload);
        foreach (UserValidator::REQUIRED_FIELDS as $key) {
            if (!array_key_exists($key, $payload) || !is_numeric($payload[$key])) {
                error_log('UserConfigStore: refusing to write invalid payload for '.$username);
                return false;
            }
        }

        return $this->writeJsonFileAtomic($this->userConfigPath($username), $payload, 0640, 'root', 'root');
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
        $users = array_replace($this->loadLegacyUsers(), $this->loadCanonicalUsers());

        ksort($users, SORT_STRING);
        return $users;
    }

    public function setSuspended(string $username, bool $suspended): bool
    {
        if (($username = $this->validatedUsername($username)) === null) {
            return false;
        }
        if (!is_array($payload = $this->get($username))) {
            return false;
        }
        $payload['suspended'] = $suspended;
        return $this->persist($username, $payload);
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
        $payload = $this->normalise($payload);
        if ($username !== null) {
            if (empty($payload['ramMiB'])) {
                $payload['ramMiB'] = $this->resolveRamMiB($username);
            }
            if (empty($payload['billingServiceId'])) {
                $payload['billingServiceId'] = $this->readBillingServiceId($username);
            }
            if (empty($payload['billingClientId'])) {
                $payload['billingClientId'] = $this->readBillingClientId($username);
            }
        }
        return $this->normalise($payload);
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
        if (!is_dir($configDir) && pmssDirEnsureExists($configDir, 0755)) {
            pmssUserFileApplyOwnership($configDir, $username);
        }

        $payload = $this->normalise($payload);
        $path = $configDir.'/pmss-user.json';
        // Root-owned, user-readable (group): cache for local tooling.
        $this->writeJsonFileAtomic($path, $payload, 0640, 'root', $username);
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
        if (($username = $this->validatedUsername($username)) === null) {
            return null;
        }

        $account = pmssUserAccountLookup($username);
        if (!is_array($account) || !isset($account['gid']) || !is_numeric($account['gid'])) {
            return null;
        }

        $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
        return pmssUserNotificationEmailRead($homeRoot.'/'.$username, (int) $account['gid']);
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

    private function loadCanonicalUsers(): array
    {
        if (!is_dir($this->userDir) || empty($files = glob($this->userDir.'/*.json'))) {
            return [];
        }

        $users = [];
        foreach ($files as $file) {
            $users[basename($file, '.json')] = pmssJsonFileReadAssoc($file);
        }
        return $this->normaliseUserMap($users);
    }

    private function normalise(array $payload): array
    {
        // Additive back-compat: map legacy rtorrentRam -> ramMiB but keep rtorrentRam if present.
        if (!isset($payload['ramMiB']) && isset($payload['rtorrentRam']) && is_numeric($payload['rtorrentRam'])) {
            $payload['ramMiB'] = (int)$payload['rtorrentRam'];
        }

        $intKeys = [
            'ramMiB', 'rtorrentPort', 'quota', 'quotaBurst', 'billingServiceId', 'billingClientId', 'trafficLimit',
            'trafficCapMbit',
            'CPUWeight', 'IOWeight', 'IOReadIOPS', 'IOWriteIOPS', 'ioLatencyMs',
        ];

        if ((!isset($payload['billingServiceId']) || !is_numeric($payload['billingServiceId']) || (int) $payload['billingServiceId'] <= 0)
            && isset($payload['billingId'])
            && is_numeric($payload['billingId'])
        ) {
            $payload['billingServiceId'] = (int) $payload['billingId'];
        }
        unset($payload['billingId']);

        foreach ($intKeys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '' && is_numeric($payload[$key])) {
                $payload[$key] = (int)$payload[$key];
            }
        }

        if (!isset($payload['billingServiceId']) || !is_numeric($payload['billingServiceId'])) {
            $payload['billingServiceId'] = 0;
        }
        if (!isset($payload['billingClientId']) || !is_numeric($payload['billingClientId'])) {
            $payload['billingClientId'] = 0;
        }

        $payload['suspended'] = array_key_exists('suspended', $payload) && (bool)$payload['suspended'];

        $payload['dockerEnabled'] = pmssUserConfigNormaliseToggleValue($payload, 'dockerEnabled');
        $payload['lighttpdEnabled'] = pmssUserConfigNormaliseToggleValue($payload, 'lighttpdEnabled');
        $payload['scheduledConfigBackup'] = pmssUserConfigNormaliseToggleValue($payload, 'scheduledConfigBackup', false);

        // Safety gate: keep rootless Docker disabled for low-memory accounts.
        if (isset($payload['ramMiB']) && is_numeric($payload['ramMiB']) && (int)$payload['ramMiB'] > 0
            && (int)$payload['ramMiB'] < pmssUserDockerMinRamMiB()) {
            $payload['dockerEnabled'] = false;
        }

        // Invariant: always write trafficLimit as 0.
        $payload['trafficLimit'] = 0;

        ksort($payload, SORT_STRING);
        return $payload;
    }

    private function loadLegacyUsers(): array
    {
        $data = pmssJsonFileReadAssoc($this->legacyAggregatePath);
        if (!is_array($data)) {
            return [];
        }

        return $this->normaliseUserMap(
            isset($data['users']) && is_array($data['users']) ? $data['users'] : $data
        );
    }

    private function normaliseUserMap(array $users): array
    {
        $normalised = [];
        foreach ($users as $name => $payload) {
            $name = (string) $name;
            if (!UserValidator::isValidUsername($name) || !is_array($payload)) {
                continue;
            }
            $normalised[$name] = $this->normalise($payload);
        }
        return $normalised;
    }

    private function writeJsonFileAtomic(string $path, array $payload, int $mode, string $owner, string $group): bool
    {
        $dir = dirname($path);
        if (!pmssDirEnsureExists($dir, 0750)) {
            return false;
        }
        $encoded = pmssJsonEncodePretty($payload);
        if ($encoded === null) {
            return false;
        }

        return pmssWriteManagedFile($path, $encoded, $owner, $group, $mode);
    }

    private function readBillingServiceId(string $username): int
    {
        if (pmssTestModeEnabled()) {
            return 0;
        }
        return pmssUserBillingServiceIdRead("/home/{$username}", true);
    }

    private function readBillingClientId(string $username): int
    {
        if (pmssTestModeEnabled()) {
            return 0;
        }
        return pmssUserBillingClientIdRead("/home/{$username}", true);
    }
}

require_once __DIR__.'/userConfigPolicy.php';
