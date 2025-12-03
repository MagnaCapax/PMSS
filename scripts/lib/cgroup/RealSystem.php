<?php

namespace PMSS\Cgroup;

require_once __DIR__ . '/SystemInterface.php';
require_once __DIR__ . '/../runtime.php'; // for requireRoot helper if needed

class RealSystem implements SystemInterface
{
    /**
     * Detect whether we are running under the hermetic test harness.
     *
     * Development tests set PMSS_TEST_MODE=1 (and a matching constant via the
     * test runner). In this mode we must avoid invoking real systemctl/findmnt
     * calls so tests stay hermetic and do not depend on host capabilities.
     */
    private function isTestMode(): bool
    {
        $flag = getenv('PMSS_TEST_MODE');
        if (is_string($flag) && $flag !== '') {
            $flag = strtolower($flag);
            if ($flag === '1' || $flag === 'true' || $flag === 'yes') {
                return true;
            }
        }
        return defined('PMSS_TEST_MODE') && PMSS_TEST_MODE;
    }
    public function getCgroupMode(): string
    {
        return is_file('/sys/fs/cgroup/cgroup.controllers') ? 'v2' : 'v1';
    }

    public function getUid(string $user): int
    {
        if (!function_exists('posix_getpwnam')) {
            return -1;
        }
        $info = posix_getpwnam($user);
        return is_array($info) && isset($info['uid']) ? (int)$info['uid'] : -1;
    }

    public function execute(string $command): ?string
    {
        if ($this->isTestMode()) {
            // In test mode we avoid shelling out to real systemctl/findmnt.
            return '';
        }
        return @shell_exec($command);
    }

    public function readFile(string $path): ?string
    {
        $content = @file_get_contents($path);
        return $content === false ? null : $content;
    }

    public function getTotalMemoryMiB(): int
    {
        $o = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($o as $line) {
            if (strpos($line, 'MemTotal:') === 0) {
                $kb = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
                return (int)round($kb / 1024);
            }
        }
        return 0;
    }

    public function resolveDevice(string $device): string
    {
        if ($device === '/home') {
            $homeDev = getenv('PMSS_HOME_DEVICE');
            if (is_string($homeDev) && $homeDev !== '') {
                return $homeDev;
            }
            return trim((string)$this->execute('findmnt -no SOURCE /home 2>/dev/null'));
        }
        return trim((string)$this->execute('findmnt -no SOURCE ' . escapeshellarg($device) . ' 2>/dev/null'));
    }

    public function requireRoot(): void
    {
        // This uses the global function from runtime.php or implements check directly
        if (function_exists('requireRoot')) {
            requireRoot();
        } elseif (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            die("This script must be run as root.\n");
        }
    }
}
