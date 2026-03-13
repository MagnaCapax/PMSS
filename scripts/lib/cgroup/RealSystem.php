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
    public function getCgroupMode(): string
    {
        return is_file('/sys/fs/cgroup/cgroup.controllers') ? 'v2' : 'v1';
    }

    public function getUid(string $user): int
    {
        $info = function_exists('posix_getpwnam') ? @posix_getpwnam($user) : false;
        return is_array($info) && isset($info['uid']) ? (int) $info['uid'] : -1;
    }

    public function execute(string $command): ?string
    {
        // In test mode we avoid shelling out to real systemctl/findmnt.
        $testMode = strtolower((string) getenv('PMSS_TEST_MODE'));
        if ((defined('PMSS_TEST_MODE') && PMSS_TEST_MODE) || $testMode === '1' || $testMode === 'true' || $testMode === 'yes') {
            return '';
        }

        return @shell_exec($command);
    }

    public function readFile(string $path): ?string
    {
        return (($content = @file_get_contents($path)) === false) ? null : $content;
    }

    public function getTotalMemoryMiB(): int
    {
        if (preg_match('/^MemTotal:\s+([0-9]+)/m', (string) @file_get_contents('/proc/meminfo'), $matches) === 1) {
            return (int) round(((int) $matches[1]) / 1024);
        }

        return 0;
    }

    public function resolveDevice(string $device): string
    {
        if ($device === '/home' && ($homeDev = getenv('PMSS_HOME_DEVICE')) !== false && $homeDev !== '') {
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
