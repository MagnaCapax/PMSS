<?php
/**
 * Handles writing traffic statistics to persistent locations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lighttpd/userFileWrite.php';

if (!function_exists('pmssTrafficNormalizeDir')) {
    /** Normalize a PMSS directory path while preserving root as `/`. */
    function pmssTrafficNormalizeDir(string $path): string
    {
        $path = rtrim($path, '/');
        return $path !== '' ? $path : '/';
    }
}

if (!function_exists('pmssTrafficDataPaths')) {
    /** @return array<string,string> Resolve the canonical per-user traffic data files. */
    function pmssTrafficDataPaths(string $username, ?string $homeDir = null): array
    {
        $homeDir = pmssTrafficNormalizeDir((string) ($homeDir ?? (getenv('PMSS_HOME_DIR') ?: '/home')));
        $userHome = $homeDir.'/'.$username;

        return [
            'normal' => $userHome.'/.trafficData',
            'local' => $userHome.'/.trafficDataLocal',
            'ingress' => $userHome.'/.trafficDataIngress',
            'ingressLocal' => $userHome.'/.trafficDataIngressLocal',
        ];
    }
}

if (!function_exists('pmssTrafficLimitPath')) {
    /** Resolve the per-user persisted traffic limit path. */
    function pmssTrafficLimitPath(string $username, ?string $homeDir = null): string
    {
        return pmssTrafficNormalizeDir((string) ($homeDir ?? (getenv('PMSS_HOME_DIR') ?: '/home'))).'/'.$username.'/.trafficLimit';
    }
}

if (!function_exists('pmssTrafficStatsPath')) {
    /** Resolve the runtime traffic statistics cache path for a user key. */
    function pmssTrafficStatsPath(string $username, ?string $statsDir = null, ?string $runtimeDir = null): string
    {
        if ($statsDir === null) {
            $statsDir = pmssTrafficNormalizeDir((string) ($runtimeDir ?? (getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss'))).'/trafficStats';
        }

        return pmssTrafficNormalizeDir($statsDir).'/'.$username;
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
            return pmssReplaceUserFile($path, $serialized, static function (string $tmpPath) use ($group, $mode): void {
                @chmod($tmpPath, $mode);
                @chown($tmpPath, 'root');
                @chgrp($tmpPath, $group);
            });
        } finally {
            $immutable && pmssTrafficSetImmutable($path, true);
        }
    }
}

class TrafficStorage
{
    private $homeDir;
    private $runtimeDir;
    private $statsDir;
    private $trafficMode;

    public function __construct(array $paths = [])
    {
        $this->homeDir = pmssTrafficNormalizeDir((string) ($paths['home_dir'] ?? (getenv('PMSS_HOME_DIR') ?: '/home')));
        $this->runtimeDir = pmssTrafficNormalizeDir((string) ($paths['runtime_dir'] ?? (getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss')));
        $this->statsDir = pmssTrafficNormalizeDir((string) ($paths['stats_dir'] ?? ($this->runtimeDir.'/trafficStats')));
        $this->trafficMode = ($paths['traffic_mode'] ?? 'egress') === 'ingress' ? 'ingress' : 'egress';
    }

    /** Ensure runtime directories exist before writing. */
    public function ensureRuntime(): void
    {
        foreach ([$this->runtimeDir => 0755, $this->statsDir => 0600] as $dir => $mode) {
            is_dir($dir) || @mkdir($dir, $mode, true);
        }
    }

    /** Persist user traffic data to home directory and runtime cache. */
    public function save(string $user, array $data): void
    {
        $isLocalUser = substr_compare($user, '-localnet', -9) === 0;
        $targetUser = $isLocalUser ? substr($user, 0, -9) : $user;
        $trafficPaths = pmssTrafficDataPaths($targetUser, $this->homeDir);
        $trafficKey = $this->trafficMode === 'ingress'
            ? ($isLocalUser ? 'ingressLocal' : 'ingress')
            : ($isLocalUser ? 'local' : 'normal');

        $serialized = serialize($data);
        $homePath = $this->homeDir.'/'.$targetUser;
        $targets = [[pmssTrafficStatsPath($user, $this->statsDir), 'root', 0600, false]];
        is_dir($homePath) && array_unshift(
            $targets,
            [$trafficPaths[$trafficKey], $targetUser, 0640, true]
        );

        foreach ($targets as [$path, $group, $mode, $immutable]) {
            pmssTrafficWriteFile($path, $serialized, $group, $mode, $immutable);
        }
    }
}
