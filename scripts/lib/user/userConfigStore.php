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
 * - dockerEnabled (bool) Rootless Docker enablement (default true; false by
 *   default for Storage Box products).
 * - CPUWeight/IOWeight/IOReadBW/... pass-through for future resource controls.
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

if (!function_exists('pmssUserDockerMinRamMiB')) {
    /**
     * Minimum RAM required for rootless Docker.
     */
    function pmssUserDockerMinRamMiB(): int
    {
        return 245;
    }
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
        if (!is_array($payload) && isset(($legacy = $this->loadLegacyAggregateMap())[$username]) && is_array($legacy[$username])) {
            $payload = $legacy[$username];
        }

        return is_array($payload) ? $this->normalise($payload) : null;
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
        $legacy = $this->loadLegacyAggregateMap();
        $users = [];
        foreach ($legacy as $name => $payload) {
            if (!UserValidator::isValidUsername($name) || !is_array($payload)) {
                continue;
            }
            $users[$name] = $this->normalise($payload);
        }

        // Overlay canonical per-user files on top of any legacy entries so mixed
        // installs (partially migrated) continue to see a complete user list.
        foreach ($this->loadFromUserDir() as $name => $payload) {
            $users[$name] = $payload;
        }

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

    public function writeUserCache(string $username, array $payload): void
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

    private function loadFromUserDir(): array
    {
        if (!is_dir($this->userDir)) {
            return [];
        }
        if (empty($files = glob($this->userDir.'/*.json'))) {
            return [];
        }

        $users = [];
        foreach ($files as $file) {
            $name = basename($file, '.json');
            if (!UserValidator::isValidUsername($name)) {
                continue;
            }
            $payload = $this->readJsonFile($file);
            if (!is_array($payload)) {
                continue;
            }
            $users[$name] = $this->normalise($payload);
        }
        return $users;
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
            'CPUWeight', 'IOWeight', 'IOReadIOPS', 'IOWriteIOPS',
        ];
        foreach ($intKeys as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '' && is_numeric($payload[$key])) {
                $payload[$key] = (int)$payload[$key];
            }
        }

        if (!isset($payload['billingId']) || !is_numeric($payload['billingId'])) {
            $payload['billingId'] = 0;
        }

        if (!array_key_exists('suspended', $payload)) {
            $payload['suspended'] = false;
        } else {
            $payload['suspended'] = (bool)$payload['suspended'];
        }

        $payload['dockerEnabled'] = array_key_exists('dockerEnabled', $payload)
            ? (!is_string($payload['dockerEnabled'])
                ? (bool) $payload['dockerEnabled']
                : !in_array(strtolower(trim($payload['dockerEnabled'])), ['false', '0', 'no', 'off', ''], true))
            : !$this->payloadDescribesStorageBox($payload);

        // Safety gate: keep rootless Docker disabled for low-memory accounts.
        if (isset($payload['ramMiB']) && is_numeric($payload['ramMiB']) && (int)$payload['ramMiB'] > 0) {
            if ((int)$payload['ramMiB'] < pmssUserDockerMinRamMiB()) {
                $payload['dockerEnabled'] = false;
            }
        }

        // Invariant: always write trafficLimit as 0.
        $payload['trafficLimit'] = 0;

        ksort($payload, SORT_STRING);
        return $payload;
    }

    private function payloadDescribesStorageBox(array $payload): bool
    {
        foreach (['product', 'productName', 'productType'] as $key) {
            $value = $payload[$key] ?? null;
            $normalized = is_string($value)
                ? preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', strtolower(trim($value))))
                : '';
            $normalized = is_string($normalized) ? $normalized : '';
            if ($normalized !== '' && strpos($normalized, 'storage') !== false && strpos($normalized, 'box') !== false) {
                return true;
            }
        }
        return false;
    }

    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function loadLegacyAggregateMap(): array
    {
        $data = $this->readJsonFile($this->legacyAggregatePath);
        return !is_array($data) ? [] : ((isset($data['users']) && is_array($data['users'])) ? $data['users'] : $data);
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
        if (!is_file($path) || is_link($path)) {
            return 0;
        }
        $owner = @fileowner($path);
        if ($owner !== 0) {
            return 0;
        }
        $raw = trim((string)@file_get_contents($path));
        if ($raw === '' || !ctype_digit($raw)) {
            return 0;
        }
        $id = (int)$raw;
        return $id > 0 ? $id : 0;
    }
}

if (!function_exists('pmssUserDockerEnabled')) {
    /**
     * Check whether rootless Docker should run for a user.
     */
    function pmssUserDockerEnabled(string $username, ?UserConfigStore $store = null): bool
    {
        $username = pmssNormalizeUsername($username);
        if (!UserValidator::isValidUsername($username)) {
            return false;
        }

        if ($store === null) {
            $store = new UserConfigStore();
        }

        $payload = $store->get($username);
        if (!is_array($payload)) {
            $payload = [];
        }

        $configuredRamMiB = 0;
        if (isset($payload['ramMiB']) && is_numeric($payload['ramMiB'])) {
            $configuredRamMiB = (int) $payload['ramMiB'];
        }

        if (array_key_exists('dockerEnabled', $payload) && !(bool) $payload['dockerEnabled']) {
            return false;
        }

        $runtimeRamMiB = $store->resolveRamMiB($username);
        $effectiveRamMiB = $configuredRamMiB;
        if ($runtimeRamMiB > 0 && ($effectiveRamMiB <= 0 || $runtimeRamMiB < $effectiveRamMiB)) {
            $effectiveRamMiB = $runtimeRamMiB;
        }

        if ($effectiveRamMiB > 0 && $effectiveRamMiB < pmssUserDockerMinRamMiB()) {
            return false;
        }

        if (!array_key_exists('dockerEnabled', $payload)) {
            return true;
        }
        return (bool) $payload['dockerEnabled'];
    }
}
