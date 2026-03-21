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
    private $trafficMode;

    public function __construct(array $paths = [])
    {
        $this->homeDir    = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $this->runtimeDir = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $this->statsDir   = rtrim($paths['stats_dir'] ?? $this->runtimeDir.'/trafficStats', '/');
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
        $filename = ($this->trafficMode === 'ingress' ? '.trafficDataIngress' : '.trafficData').($isLocalUser ? 'Local' : '');

        $serialized = serialize($data);
        $homePath = $this->homeDir.'/'.$targetUser;
        $targets = [[$this->statsDir.'/'.$user, 'root', 0600, false]];
        is_dir($homePath) && array_unshift($targets, [$homePath.'/'.$filename, $targetUser, 0640, true]);

        foreach ($targets as [$path, $group, $mode, $immutable]) {
            $immutable && $this->setImmutable($path, false);
            @file_put_contents($path, $serialized);
            @chown($path, 'root');
            @chgrp($path, $group);
            @chmod($path, $mode);
            $immutable && $this->setImmutable($path, true);
        }
    }

    /**
     * Toggle immutable bit when supported (best-effort).
     */
    private function setImmutable(string $path, bool $enable): void
    {
        if (!is_file($path)) {
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
        if ($chattr === '') {
            return;
        }
        @exec($chattr.' '.($enable ? '+i' : '-i').' '.escapeshellarg($path).' 2>/dev/null');
    }
}
