<?php
/**
 * Library helper: Real System.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

require_once __DIR__ . '/SystemInterface.php';
require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/../runtime.php'; // for requireRoot helper if needed
require_once __DIR__ . '/../userLifecycle.php';

class RealSystem implements SystemInterface
{
    public function getCgroupMode(): string
    {
        return \pmssCgroupModeWithDefault('v1');
    }

    public function getUid(string $user): int
    {
        $info = \pmssUserAccountLookup($user);
        return $info !== null ? (int) $info['uid'] : -1;
    }

    public function execute(string $command): ?string
    {
        // In test mode we avoid shelling out to real systemctl/findmnt.
        if (\pmssTestModeEnabled()) {
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
        return \pmssProcMeminfoTotalMiBRead();
    }

    public function resolveDevice(string $device): string
    {
        if ($device === '/home' && ($homeDev = getenv('PMSS_HOME_DEVICE')) !== false && $homeDev !== '') {
            return $homeDev;
        }

        return \pmssCgroupPolicyMountSourceResolve($device, function (string $command): string {
            return trim((string) $this->execute($command));
        });
    }

    public function requireRoot(): void
    {
        \requireRoot();
    }
}
