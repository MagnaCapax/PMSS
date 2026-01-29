<?php
/**
 * Handles writing traffic statistics to persistent locations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

class TrafficStorage
{
    private $homeDir;
    private $runtimeDir;
    private $statsDir;
    private $chattrPath;
    private $trafficMode;

    public function __construct(array $paths = [])
    {
        $this->homeDir    = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $this->runtimeDir = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $this->statsDir   = rtrim($paths['stats_dir'] ?? $this->runtimeDir.'/trafficStats', '/');
        $this->chattrPath = null;
        $mode = $paths['traffic_mode'] ?? 'egress';
        $this->trafficMode = in_array($mode, ['egress', 'ingress'], true) ? $mode : 'egress';
    }

    /** Ensure runtime directories exist before writing. */
    public function ensureRuntime(): void
    {
        if (!is_dir($this->runtimeDir)) {
            @mkdir($this->runtimeDir, 0755, true);
        }
        if (!is_dir($this->statsDir)) {
            @mkdir($this->statsDir, 0600, true);
        }
    }

    /** Persist user traffic data to home directory and runtime cache. */
    public function save(string $user, array $data): void
    {
        [$targetUser, $filename] = $this->resolveTrafficFilename($user);

        $serialized = serialize($data);
        $homePath   = $this->homeDir.'/'.$targetUser;

        if (is_dir($homePath)) {
            $userPath = $homePath.'/'.$filename;
            $this->setImmutable($userPath, false);
            @file_put_contents($userPath, $serialized);
            $this->protectUserTrafficFile($userPath, $targetUser);
            $this->setImmutable($userPath, true);
        }

        $runtimePath = $this->statsDir.'/'.$user;
        @file_put_contents($runtimePath, $serialized);
        $this->protectRuntimeFile($runtimePath);
    }

    /**
     * Enforce root ownership and read-only access for tenants.
     */
    private function protectUserTrafficFile(string $path, string $group): void
    {
        @chown($path, 'root');
        if ($group !== '') {
            @chgrp($path, $group);
        }
        @chmod($path, 0640);
    }

    /**
     * Restrict runtime cache files to root-only access.
     */
    private function protectRuntimeFile(string $path): void
    {
        @chown($path, 'root');
        @chgrp($path, 'root');
        @chmod($path, 0600);
    }

    /**
     * Resolve the traffic data filename and target user for the given label.
     *
     * Supports the existing "-localnet" suffix and a traffic mode selector.
     *
     * @return array{0:string,1:string} [targetUser, filename]
     */
    private function resolveTrafficFilename(string $user): array
    {
        $targetUser = $user;
        $suffix = '';
        $localSuffix = '-localnet';

        if (substr($user, -strlen($localSuffix)) === $localSuffix) {
            $suffix = 'Local';
            $targetUser = substr($user, 0, -strlen($localSuffix));
        }

        $base = $this->trafficMode === 'ingress' ? '.trafficDataIngress' : '.trafficData';
        if ($suffix !== '') {
            $base .= $suffix;
        }

        return [$targetUser, $base];
    }

    /**
     * Toggle immutable bit when supported (best-effort).
     */
    private function setImmutable(string $path, bool $enable): void
    {
        if (!is_file($path)) {
            return;
        }
        $chattr = $this->chattrPath();
        if ($chattr === '') {
            return;
        }
        $flag = $enable ? '+i' : '-i';
        @exec($chattr.' '.$flag.' '.escapeshellarg($path).' 2>/dev/null');
    }

    private function chattrPath(): string
    {
        if ($this->chattrPath !== null) {
            return $this->chattrPath;
        }
        $this->chattrPath = '';
        foreach (['/usr/bin/chattr', '/bin/chattr'] as $candidate) {
            if (is_executable($candidate)) {
                $this->chattrPath = $candidate;
                break;
            }
        }
        return $this->chattrPath;
    }
}
