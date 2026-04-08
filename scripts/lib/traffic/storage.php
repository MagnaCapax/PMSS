<?php
/**
 * Handles writing traffic statistics to persistent locations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../userLifecycle.php';

if (!function_exists('pmssTrafficDataPaths')) {
    /** @return array<string,string> Resolve the canonical per-user traffic data files. */
    function pmssTrafficDataPaths(string $username, ?string $homeDir = null): array
    {
        $homeDir = pmssDirPathResolve($homeDir, 'PMSS_HOME_DIR', '/home');
        $userHome = $homeDir.'/'.$username;

        return [
            'normal' => $userHome.'/.trafficData',
            'local' => $userHome.'/.trafficDataLocal',
            'ingress' => $userHome.'/.trafficDataIngress',
            'ingressLocal' => $userHome.'/.trafficDataIngressLocal',
        ];
    }
}

if (!function_exists('pmssTrafficDataPathKey')) {
    /** Resolve the traffic file key for one mode/localnet combination. */
    function pmssTrafficDataPathKey(bool $isLocalnet, string $trafficMode = 'egress'): string { return ['egress' => ['normal', 'local'], 'ingress' => ['ingress', 'ingressLocal']][$trafficMode === 'ingress' ? 'ingress' : 'egress'][$isLocalnet ? 1 : 0]; }
}

if (!function_exists('pmssTrafficUserKeyIsLocalnet')) {
    // Detect whether a traffic user key targets the localnet bucket.
    function pmssTrafficUserKeyIsLocalnet(string $user): bool { return substr_compare($user, '-localnet', -9) === 0; }
}

if (!function_exists('pmssTrafficUserKeyBaseUser')) {
    // Resolve the canonical PMSS username behind a traffic user key.
    function pmssTrafficUserKeyBaseUser(string $user): string { return pmssTrafficUserKeyIsLocalnet($user) ? substr($user, 0, -9) : $user; }
}

if (!function_exists('pmssTrafficUserKeyIsValid')) {
    /** Validate a traffic storage key, allowing the `-localnet` suffix. */
    function pmssTrafficUserKeyIsValid(string $user): bool
    {
        return $user !== '' && pmssUsernameIsValid(pmssTrafficUserKeyBaseUser($user));
    }
}

if (!function_exists('pmssTrafficLimitPath')) {
    /** Resolve the per-user persisted traffic limit path. */
    function pmssTrafficLimitPath(string $username, ?string $homeDir = null): string
    {
        return pmssDirPathResolve($homeDir, 'PMSS_HOME_DIR', '/home').'/'.$username.'/.trafficLimit';
    }
}

if (!function_exists('pmssTrafficStatsPath')) {
    /** Resolve the runtime traffic statistics cache path for a user key. */
    function pmssTrafficStatsPath(string $username, ?string $statsDir = null, ?string $runtimeDir = null): string
    {
        if ($statsDir === null) {
            $statsDir = pmssDirPathResolve($runtimeDir, 'PMSS_RUNTIME_DIR', '/var/run/pmss').'/trafficStats';
        }

        return pmssDirPathNormalize($statsDir).'/'.$username;
    }
}

if (!function_exists('pmssTrafficReadSerializedArrayFile')) {
    /**
     * Read a serialized array payload without allowing object wakeups.
     */
    function pmssTrafficReadSerializedArrayFile(string $path): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = @unserialize($raw, ['allowed_classes' => false]);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('pmssTrafficReadRootOwnedStatsPayload')) {
    /**
     * Read a trusted traffic stats payload owned by root and grouped to the user.
     */
    function pmssTrafficReadRootOwnedStatsPayload(string $path, string $username): ?array
    {
        $stats = @stat($path);
        if ($stats === false || (int) $stats['uid'] !== 0 || (($stats['mode'] & 0777) & 0022) !== 0) {
            return null;
        }

        $group = @posix_getgrgid((int) $stats['gid']);
        if ($group !== false && isset($group['name']) && $group['name'] !== $username && $group['name'] !== 'root') {
            return null;
        }

        $data = pmssTrafficReadSerializedArrayFile($path);
        if ($data === null || !isset($data['raw']['month']) || !is_numeric($data['raw']['month'])) {
            return null;
        }

        return $data;
    }
}

if (!function_exists('pmssTrafficSetImmutable')) {
    /** Best-effort immutable toggle for traffic data files. */
    function pmssTrafficSetImmutable(string $path, bool $enable): void
    {
        if (!is_file($path) || is_link($path)) {
            return;
        }

        static $chattr = null;
        if ($chattr === null) {
            $chattr = '';
            foreach (['/usr/bin/chattr', '/bin/chattr'] as $candidate) {
                if (is_executable($candidate)) {
                    $chattr = $candidate;
                    break;
                }
            }
        }

        $chattr === '' || @exec($chattr.' '.($enable ? '+i' : '-i').' '.escapeshellarg($path).' 2>/dev/null');
    }
}

if (!function_exists('pmssTrafficWriteFile')) {
    /**
     * Persist one traffic state file through the shared atomic writer.
     */
    function pmssTrafficWriteFile(string $path, string $serialized, string $group, int $mode, bool $immutable): bool
    {
        $immutable && pmssTrafficSetImmutable($path, false);

        try {
            return pmssWriteManagedFile($path, $serialized, 'root', $group, $mode);
        } finally {
            $immutable && pmssTrafficSetImmutable($path, true);
        }
    }
}

if (!function_exists('pmssManagedDirsEnsure')) {
    /** Ensure each managed directory exists, reporting unsafe paths through the callback. */
    function pmssManagedDirsEnsure(array $directories, callable $failureLogger): void { foreach ($directories as $dir => $mode) { pmssEnsureSafeDir((string) $dir, (int) $mode) || $failureLogger((string) $dir); } }
}

if (!function_exists('pmssManagedSerializedTargetsWrite')) {
    /** Write one serialized payload to each managed target while preserving partial success. */
    function pmssManagedSerializedTargetsWrite(string $serialized, array $targets, callable $failureLogger): bool
    {
        $allWritesSucceeded = true;
        foreach ($targets as list($path, $group, $mode, $immutable)) {
            if (!pmssTrafficWriteFile((string) $path, $serialized, (string) $group, (int) $mode, (bool) $immutable)) {
                $allWritesSucceeded = false;
                $failureLogger((string) $path);
            }
        }
        return $allWritesSucceeded;
    }
}

if (!function_exists('pmssTrafficSeedInitialState')) {
    /** Persist zeroed traffic state for new accounts via the canonical storage helper. */
    function pmssTrafficSeedInitialState(string $username, ?string $homeDir = null, ?string $runtimeDir = null, ?callable $logger = null): bool
    {
        $failed = false;
        $forwardLogger = $logger ?? 'logMessage';
        $storage = new TrafficStorage(['home_dir' => $homeDir, 'runtime_dir' => $runtimeDir, 'logger' => static function (string $message) use (&$failed, $forwardLogger): void { $failed = true; $forwardLogger($message); }]);
        $payload = ['raw' => array_fill_keys(array_keys(pmssStatsCompareTimesBuild(0)), 0.0), 'daily' => []];
        $storage->ensureRuntime();
        $storage->save($username, $payload);
        $storage->save($username.'-localnet', $payload);
        return !$failed;
    }
}

class TrafficStorage
{
    private $homeDir;
    private $runtimeDir;
    private $statsDir;
    private $trafficMode;
    private $logger;

    public function __construct(array $paths = [])
    {
        $this->homeDir = pmssDirPathResolve($paths['home_dir'] ?? null, 'PMSS_HOME_DIR', '/home');
        $this->runtimeDir = pmssDirPathResolve($paths['runtime_dir'] ?? null, 'PMSS_RUNTIME_DIR', '/var/run/pmss');
        $this->statsDir = pmssDirPathNormalize((string) ($paths['stats_dir'] ?? ($this->runtimeDir.'/trafficStats')));
        $this->trafficMode = ($paths['traffic_mode'] ?? 'egress') === 'ingress' ? 'ingress' : 'egress';
        $this->logger = $paths['logger'] ?? 'logMessage';
    }

    /** Emit a storage warning through the configured logger. */
    private function log(string $message): void
    {
        $logger = $this->logger;
        $logger($message);
    }

    /** Ensure runtime directories exist before writing. */
    public function ensureRuntime(): void
    {
        pmssManagedDirsEnsure([$this->runtimeDir => 0755, $this->statsDir => 0600], function (string $dir): void { $this->log('[WARN] Unable to prepare traffic runtime directory '.$dir); });
    }

    /** Persist user traffic data to home directory and runtime cache. */
    public function save(string $user, array $data): void
    {
        if (!pmssTrafficUserKeyIsValid($user)) {
            return;
        }

        $isLocalUser = pmssTrafficUserKeyIsLocalnet($user);
        $targetUser = pmssTrafficUserKeyBaseUser($user);
        $homeTrafficPath = pmssTrafficDataPaths($targetUser, $this->homeDir)[pmssTrafficDataPathKey($isLocalUser, $this->trafficMode)];

        $serialized = serialize($data);
        $targets = [[pmssTrafficStatsPath($user, $this->statsDir), 'root', 0600, false]];
        is_dir($this->homeDir.'/'.$targetUser) && array_unshift($targets, [$homeTrafficPath, $targetUser, 0640, true]);
        pmssManagedSerializedTargetsWrite($serialized, $targets, function (string $path) use ($user): void { $this->log('[WARN] Failed to write traffic state for '.$user.' at '.$path); });
    }
}
