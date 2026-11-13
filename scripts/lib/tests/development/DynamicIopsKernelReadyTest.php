<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/dynamicIopsPolicy.php';

/** ADR 0271: read caps follow the kernel, never a per-host setting. */
class DynamicIopsKernelReadyTest extends TestCase
{
    private function cgroupRoot(bool $memory, bool $blkio): string
    {
        $root = $this->pmssMakeTempDir('pmss-kready-', 0700);
        $files = [];
        if ($memory) $files['memory/memory.limit_in_bytes'] = "9223372036854771712\n";
        if ($blkio) $files['blkio/blkio.throttle.read_iops_device'] = '';
        return $this->pmssWriteFiles($root, $files, 0700);
    }

    public function testReleaseAndControllers(): void
    {
        $both = $this->cgroupRoot(true, true);
        foreach ([
            '6.1.0-50-amd64' => false, '6.11.9' => false, '5.19.0' => false, 'garbage' => false, '' => false,
            '6.12.57+deb13-amd64' => true, '6.14.11-4-pve' => true, '7.0.14-28-pve' => true, '10.2.0' => true,
        ] as $release => $expected) {
            $this->assertSame($expected, \pmssDynamicIopsKernelReady($release, $both), $release);
        }
        // Stock Debian 6.12+ (no CONFIG_MEMCG_V1) and a cgroup-v2-only host have no v1 controllers.
        $this->assertSame(false, \pmssDynamicIopsKernelReady('7.0.14-28-pve', $this->cgroupRoot(false, true)));
        $this->assertSame(false, \pmssDynamicIopsKernelReady('7.0.14-28-pve', $this->cgroupRoot(true, false)));
        $this->assertSame(false, \pmssDynamicIopsKernelReady('7.0.14-28-pve', $this->cgroupRoot(false, false)));
    }

    public function testAutoResolvesToOffOrEnforce(): void
    {
        $settings = \pmssDynamicIopsSettings(['cgroup' => ['dynamicIops' => ['mode' => 'auto']]]);
        $this->assertSame(\pmssDynamicIopsKernelReady() ? 'enforce' : 'off', $settings['mode']);
        $this->assertSame(null, \pmssDynamicIopsSettings(['cgroup' => ['dynamicIops' => ['mode' => 'maybe']]]));
    }

    public function testShippedPolicyIsAuto(): void
    {
        $policy = require __DIR__.'/../../../../etc/seedbox/config/cgroup.policy.php';
        $this->assertSame('auto', $policy['cgroup']['dynamicIops']['mode']);
    }
}
