<?php
/**
 * Handles writing traffic statistics to persistent locations.
 */

class TrafficStorage
{
    private $homeDir;
    private $runtimeDir;
    private $statsDir;

    public function __construct(array $paths = [])
    {
        $this->homeDir    = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $baseRuntime      = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $this->runtimeDir = $baseRuntime;
        $this->statsDir   = rtrim($paths['stats_dir'] ?? $baseRuntime.'/trafficStats', '/');
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
        $filename   = '.trafficData';

        if (strpos($user, '-localnet') !== false) {
            $filename   = '.trafficDataLocal';
            $targetUser = str_replace('-localnet', '', $user);
        }

        $serialized = serialize($data);
        $homePath   = $this->homeDir.'/'.$targetUser;

        if (is_dir($homePath)) {
            $userPath = $homePath.'/'.$filename;
            @file_put_contents($userPath, $serialized);
            $this->protectUserTrafficFile($userPath, $targetUser);
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
}
