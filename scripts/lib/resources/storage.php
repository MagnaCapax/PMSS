<?php
/**
 * Handles writing resource statistics to persistent locations.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

class ResourceStorage
{
    private $homeDir;
    private $runtimeDir;
    private $statsDir;
    private $chattrPath;

    public function __construct(array $paths = [])
    {
        $this->homeDir = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $this->runtimeDir = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $this->statsDir = rtrim($paths['stats_dir'] ?? $this->runtimeDir.'/resourceStats', '/');
        $this->chattrPath = null;
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

    /** Persist user resource data to home directory and runtime cache. */
    public function save(string $user, array $data): void
    {
        $serialized = serialize($data);
        $homePath = $this->homeDir.'/'.$user;

        if (is_dir($homePath)) {
            $userPath = $homePath.'/.resourceData';
            $this->setImmutable($userPath, false);
            @file_put_contents($userPath, $serialized);
            $this->protectUserResourceFile($userPath, $user);
            $this->setImmutable($userPath, true);
        }

        $runtimePath = $this->statsDir.'/'.$user;
        @file_put_contents($runtimePath, $serialized);
        $this->protectRuntimeFile($runtimePath);
    }

    /**
     * Enforce root ownership and read-only access for tenants.
     */
    private function protectUserResourceFile(string $path, string $group): void
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
