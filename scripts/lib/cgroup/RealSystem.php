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
require_once __DIR__ . '/../userLifecycle.php';

class RealSystem implements SystemInterface
{
    public function getCgroupMode(): string
    {
        return is_file('/sys/fs/cgroup/cgroup.controllers') ? 'v2' : 'v1';
    }

    public function getUid(string $user): int
    {
        $info = \pmssUserAccountLookup($user);
        return $info !== null ? (int) $info['uid'] : -1;
    }

    public function execute(string $command): ?string
    {
        // In test mode we avoid shelling out to real systemctl/findmnt.
        if ((defined('PMSS_TEST_MODE') && PMSS_TEST_MODE)
            || in_array(strtolower((string) getenv('PMSS_TEST_MODE')), ['1', 'true', 'yes'], true)) {
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
        return preg_match('/^MemTotal:\s+([0-9]+)/m', (string) @file_get_contents('/proc/meminfo'), $matches) === 1
            ? (int) round(((int) $matches[1]) / 1024)
            : 0;
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
