<?php
/**
 * Library helper: Real System.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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
        return in_array(strtolower((string) getenv('PMSS_TEST_MODE')), ['1', 'true', 'yes'], true)
            || (defined('PMSS_TEST_MODE') && PMSS_TEST_MODE);
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
        // In test mode we avoid shelling out to real systemctl/findmnt.
        return $this->isTestMode() ? '' : @shell_exec($command);
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
        if ($device === '/home' && is_string($homeDev = getenv('PMSS_HOME_DEVICE')) && $homeDev !== '') {
            return $homeDev;
        }

        $target = ($device === '/home') ? '/home' : escapeshellarg($device);
        return trim((string) $this->execute('findmnt -no SOURCE '.$target.' 2>/dev/null'));
    }

    public function requireRoot(): void
    {
        if (function_exists('requireRoot')) {
            requireRoot();
            return;
        }

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            die("This script must be run as root.\n");
        }
    }
}
