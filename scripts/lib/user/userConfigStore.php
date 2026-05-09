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
 * Known keys (documented for operators; not a strict schema):
 * - ramMiB (int)       Account RAM in MiB. (rTorrent RAM is derived elsewhere.)
 * - rtorrentPort (int) Assigned SCGI port for rTorrent.
 * - quota (int)        Disk quota in GiB.
 * - quotaBurst (int)   Burst quota in GiB (typically 125%).
 * - billingId (int)    0 when missing; fallback reads /home/<user>/.billingId if root-owned.
 * - trafficLimit (int) Always written as 0 (traffic caps live in runtime files).
 * - trafficCapMbit (int) Post-limit ceiling in Mbit (0/absent uses server default).
 * - suspended (bool)   Best-effort mirror of suspension state (marker remains www-disabled).
 * - dockerEnabled (bool) Rootless Docker enablement (default true unless the
 *   provisioner stores an explicit override).
 * - lighttpdEnabled (bool) Per-user lighttpd/php-cgi watchdog enablement
 *   (default true unless the operator stores an explicit override).
 * - CPUWeight/IOWeight/IOReadBW/... and ioCostQos/ioCostModel pass-through for
 *   cgroup resource controls.
 *
 * #TODO(Q4/2027): Remove legacy /etc/seedbox/runtime/users.json fallback.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

require_once __DIR__.'/UserValidator.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../systemdSliceProperties.php';

/**
 * Minimum RAM required for rootless Docker.
 */
function pmssUserDockerMinRamMiB(): int
{
    return 245;
}

/** Convert stored feature-toggle values to a stable boolean. */
function pmssUserConfigNormaliseToggleValue(array $payload, string $key, bool $default = true): bool
{
    if (!array_key_exists($key, $payload)) {
        return $default;
    }
    $value = $payload[$key];

    return is_string($value)
        ? !in_array(strtolower(trim($value)), ['false', '0', 'no', 'off', ''], true)
        : (bool) $value;
}

/** Load one validated user's persisted payload for policy checks. */
function pmssUserConfigResolvePayload(string $username, ?UserConfigStore &$store = null): ?array
{
    $username = pmssNormalizeUsername($username);
    if (!UserValidator::isValidUsername($username)) {
        return null;
    }

    $store = $store ?: new UserConfigStore();
    $payload = $store->get($username);
    return is_array($payload) ? $payload : [];
}

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

        $payload = $this->readJsonFile($this->userConfigPath($username));
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
            if (empty($payload['billingId'])) {
                $payload['billingId'] = $this->readBillingId($username);
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
            @chown($configDir, $username);
            @chgrp($configDir, $username);
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
        return ($username = $this->validatedUsername($username)) === null ? 0 : $this->resolveRamMiBFromSystemdSlice($username);
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
        if (!is_dir($this->userDir)) {
            return [];
        }
        if (empty($files = glob($this->userDir.'/*.json'))) {
            return [];
        }

        $users = [];
        foreach ($files as $file) {
            $users[basename($file, '.json')] = $this->readJsonFile($file);
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
            'ramMiB', 'rtorrentPort', 'quota', 'quotaBurst', 'billingId', 'trafficLimit',
            'trafficCapMbit',
            'CPUWeight', 'IOWeight', 'IOReadIOPS', 'IOWriteIOPS', 'ioLatencyMs',
        ];
        foreach ($intKeys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '' && is_numeric($payload[$key])) {
                $payload[$key] = (int)$payload[$key];
            }
        }

        if (!isset($payload['billingId']) || !is_numeric($payload['billingId'])) {
            $payload['billingId'] = 0;
        }

        $payload['suspended'] = array_key_exists('suspended', $payload) && (bool)$payload['suspended'];

        $payload['dockerEnabled'] = pmssUserConfigNormaliseToggleValue($payload, 'dockerEnabled');
        $payload['lighttpdEnabled'] = pmssUserConfigNormaliseToggleValue($payload, 'lighttpdEnabled');

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

    private function readJsonFile(string $path): ?array
    {
        return pmssJsonFileReadAssoc($path);
    }

    private function loadLegacyUsers(): array
    {
        $data = $this->readJsonFile($this->legacyAggregatePath);
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
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }

        return pmssWriteManagedFile($path, $encoded, $owner, $group, $mode);
    }

    private function resolveRamMiBFromSystemdSlice(string $username): int
    {
        if (getenv('PMSS_TEST_MODE') === '1') {
            return 0;
        }
        $limits = pmssReadUserSlicePropertiesByUsername($username, ['MemoryHigh', 'MemoryMax']);
        $bytes = (int) (pmssSystemdPropertyTrailingInt($limits['MemoryMax']) ?: pmssSystemdPropertyTrailingInt($limits['MemoryHigh']) ?: 0);
        if ($bytes <= 0) {
            return 0;
        }

        $mib = (int)floor($bytes / 1048576);
        return max(0, $mib);
    }

    private function readBillingId(string $username): int
    {
        if (getenv('PMSS_TEST_MODE') === '1') {
            return 0;
        }
        $path = "/home/{$username}/.billingId";
        if (!is_file($path) || is_link($path) || @fileowner($path) !== 0) {
            return 0;
        }

        $raw = pmssReadRegularFileDigits($path);
        return ($raw !== null && (int) $raw > 0) ? (int) $raw : 0;
    }
}

/**
 * Check whether rootless Docker should run for a user.
 */
function pmssUserDockerEnabled(string $username, ?UserConfigStore $store = null): bool
{
    $username = pmssNormalizeUsername($username);
    $payload = pmssUserConfigResolvePayload($username, $store);
    if ($payload === null) {
        return false;
    }

    if (!pmssUserConfigNormaliseToggleValue($payload, 'dockerEnabled')) {
        return false;
    }

    $configuredRamMiB = isset($payload['ramMiB']) && is_numeric($payload['ramMiB']) ? (int) $payload['ramMiB'] : 0;

    $runtimeRamMiB = $store->resolveRamMiB($username);
    $effectiveRamMiB = $configuredRamMiB;
    if ($runtimeRamMiB > 0 && ($effectiveRamMiB <= 0 || $runtimeRamMiB < $effectiveRamMiB)) {
        $effectiveRamMiB = $runtimeRamMiB;
    }

    if ($effectiveRamMiB > 0 && $effectiveRamMiB < pmssUserDockerMinRamMiB()) {
        return false;
    }

    return true;
}

/**
 * Check whether the per-user lighttpd/php-cgi web stack should run.
 */
function pmssUserLighttpdEnabled(string $username, ?UserConfigStore $store = null): bool
{
    $payload = pmssUserConfigResolvePayload($username, $store);
    if ($payload === null) {
        return false;
    }

    return pmssUserConfigNormaliseToggleValue($payload, 'lighttpdEnabled');
}
