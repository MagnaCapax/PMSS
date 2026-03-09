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
        $targetUser = $user;
        $filename = $this->trafficMode === 'ingress' ? '.trafficDataIngress' : '.trafficData';
        $localSuffix = '-localnet';
        if (substr($user, -strlen($localSuffix)) === $localSuffix) {
            $targetUser = substr($user, 0, -strlen($localSuffix));
            $filename .= 'Local';
        }

        $serialized = serialize($data);
        $homePath   = $this->homeDir.'/'.$targetUser;

        if (is_dir($homePath)) {
            $userPath = $homePath.'/'.$filename;
            $this->setImmutable($userPath, false);
            @file_put_contents($userPath, $serialized);
            @chown($userPath, 'root');
            if ($targetUser !== '') {
                @chgrp($userPath, $targetUser);
            }
            @chmod($userPath, 0640);
            $this->setImmutable($userPath, true);
        }

        $runtimePath = $this->statsDir.'/'.$user;
        @file_put_contents($runtimePath, $serialized);
        @chown($runtimePath, 'root');
        @chgrp($runtimePath, 'root');
        @chmod($runtimePath, 0600);
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
