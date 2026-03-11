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

    public function __construct(array $paths = [])
    {
        $this->homeDir = rtrim($paths['home_dir'] ?? getenv('PMSS_HOME_DIR') ?: '/home', '/');
        $this->runtimeDir = rtrim($paths['runtime_dir'] ?? getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
        $this->statsDir = rtrim($paths['stats_dir'] ?? $this->runtimeDir.'/resourceStats', '/');
    }

    /** Ensure runtime directories exist before writing. */
    public function ensureRuntime(): void
    {
        foreach ([$this->runtimeDir => 0755, $this->statsDir => 0600] as $dir => $mode) {
            if (!is_dir($dir)) {
                @mkdir($dir, $mode, true);
            }
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
            $this->writeAtomic($userPath, $serialized);
            @chown($userPath, 'root');
            if ($user !== '') {
                @chgrp($userPath, $user);
            }
            @chmod($userPath, 0640);
            $this->setImmutable($userPath, true);
        }

        $runtimePath = $this->statsDir.'/'.$user;
        $this->writeAtomic($runtimePath, $serialized);
        @chown($runtimePath, 'root');
        @chgrp($runtimePath, 'root');
        @chmod($runtimePath, 0600);
    }

    /**
     * Write payloads atomically to avoid partial truncation on interruption.
     */
    private function writeAtomic(string $path, string $payload): void
    {
        $tmp = $path.'.tmp.'.getmypid().'.'.mt_rand(1000, 9999);
        if (@file_put_contents($tmp, $payload) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
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
        $chattr = '';
        foreach (['/usr/bin/chattr', '/bin/chattr'] as $candidate) {
            if (is_executable($candidate)) {
                $chattr = $candidate;
                break;
            }
        }
        if ($chattr === '') {
            return;
        }
        $flag = $enable ? '+i' : '-i';
        @exec($chattr.' '.$flag.' '.escapeshellarg($path).' 2>/dev/null');
    }
}
