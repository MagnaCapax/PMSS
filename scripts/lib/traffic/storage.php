<?php
/**
 * Handles writing traffic statistics to persistent locations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../user/identity.php';
require_once __DIR__.'/../user/integerSetting.php';

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

/** Resolve the traffic file key for one mode/localnet combination. */
function pmssTrafficDataPathKey(bool $isLocalnet, string $trafficMode = 'egress'): string { return ['egress' => ['normal', 'local'], 'ingress' => ['ingress', 'ingressLocal']][$trafficMode === 'ingress' ? 'ingress' : 'egress'][$isLocalnet ? 1 : 0]; }

// Detect whether a traffic user key targets the localnet bucket.
function pmssTrafficUserKeyIsLocalnet(string $user): bool { return substr_compare($user, '-localnet', -9) === 0; }

// Resolve the canonical PMSS username behind a traffic user key.
function pmssTrafficUserKeyBaseUser(string $user): string { return pmssTrafficUserKeyIsLocalnet($user) ? substr($user, 0, -9) : $user; }

/** Validate a traffic storage key, allowing the `-localnet` suffix. */
function pmssTrafficUserKeyIsValid(string $user): bool
{
    return $user !== '' && pmssUsernameIsValid(pmssTrafficUserKeyBaseUser($user));
}

/** Resolve the per-user persisted traffic limit path. */
function pmssTrafficLimitPath(string $username, ?string $homeDir = null): string
{
    return pmssIntegerSettingUserHomePath($username, '.trafficLimit', $homeDir);
}

/** Resolve the runtime traffic statistics cache path for a user key. */
function pmssTrafficStatsPath(string $username, ?string $statsDir = null, ?string $runtimeDir = null): string
{
    if ($statsDir === null) {
        $statsDir = pmssDirPathResolve($runtimeDir, 'PMSS_RUNTIME_DIR', '/var/run/pmss').'/trafficStats';
    }

    return pmssDirPathNormalize($statsDir).'/'.$username;
}

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

    $data = pmssReadSerializedArrayFile($path);
    if ($data === null || !isset($data['raw']['month']) || !is_numeric($data['raw']['month'])) {
        return null;
    }

    return $data;
}

/** Compatibility wrapper for traffic permission repair callers. */
function pmssTrafficSetImmutable(string $path, bool $enable): void { pmssManagedFileImmutableSet($path, $enable); }

/** Compatibility wrapper for older traffic-state callers. */
function pmssTrafficWriteFile(string $path, string $serialized, string $group, int $mode, bool $immutable): bool { return pmssManagedSerializedTargetsWrite($serialized, [[$path, $group, $mode, $immutable]], static function (string $_path): void {}); }

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
