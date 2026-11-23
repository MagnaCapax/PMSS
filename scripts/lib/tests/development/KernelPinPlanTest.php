<?php
/** Pure kernel-delivery decisions; no package manager or host observations. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/kernelPin.php';

class KernelPinPlanTest extends TestCase
{
    public function testSkipAndBoundaryMatrix(): void
    {
        $ready = [
            'distro' => ['name' => 'debian', 'version' => 12], 'status' => '', 'version' => '',
            'hold' => null, 'dkms' => [], 'bootFree' => 250 * 1048576, 'rootFree' => 1300 * 1048576,
            'bpfPlanner' => true,
        ];
        $cases = [
            [[], ''],
            [['distro' => ['name' => 'debian', 'version' => 11]], 'requires Debian 12'],
            [['distro' => ['name' => 'debian', 'version' => 13]], 'requires Debian 12'],
            [['distro' => ['name' => 'ubuntu', 'version' => 12]], 'requires Debian 12'],
            [['status' => 'install ok installed', 'version' => PMSS_KERNEL_PIN_VERSION], 'already installed'],
            [['status' => 'hold ok installed', 'version' => PMSS_KERNEL_PIN_VERSION], 'already installed'],
            [['status' => 'deinstall ok config-files', 'version' => PMSS_KERNEL_PIN_VERSION], ''],
            [['status' => 'install ok unpacked', 'version' => PMSS_KERNEL_PIN_VERSION], ''],
            [['status' => 'install ok installed', 'version' => 'older'], ''],
            [['hold' => 'trial stopped'], 'kernel-pin-hold: trial stopped'],
            [['hold' => ''], 'safety hold (no readable reason)'],
            [['dkms' => ['wireguard']], ''],
            [['dkms' => ['wireguard', 'zfs']], 'DKMS module registered: zfs'],
            [['dkms' => ['nvidia', 'zfs']], 'DKMS module registered: nvidia, zfs'],
            [['dkms' => null], 'cannot inspect DKMS'],
            [['bootFree' => 250 * 1048576 - 1], '/boot free space 262143999 bytes (250.0 MiB); required 250 MiB'],
            [['rootFree' => 1300 * 1048576 - 1], '/ free space 1363148799 bytes (1300.0 MiB); required 1300 MiB'],
            [['bootFree' => 0], '/boot free space 0 bytes (0.0 MiB); required 250 MiB'],
            [['rootFree' => false], '/ free space unknown; required 1300 MiB'],
            [['bootFree' => false], '/boot free space unknown; required 250 MiB'],
            [['bpfPlanner' => false], 'refusal: #1303 BPF LSM planner'],
            [['hold' => 'stop', 'dkms' => ['zfs'], 'bootFree' => 0], 'kernel-pin-hold: stop'],
        ];
        foreach ($cases as $index => [$overrides, $reason]) {
            $plan = \pmssPinnedKernelPlan(array_replace($ready, $overrides));
            $this->assertSame($reason === '' ? 'install' : 'skip', $plan['action'], 'case '.$index);
            if ($reason === '') $this->assertSame('', $plan['reason']);
            else $this->assertStringContainsString($reason, $plan['reason'], 'case '.$index);
        }
    }

    public function testStepRunsBeforeBootDefaultsAndIsSoftFail(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/update-step2.php');
        $this->assertStringContainsString(
            "['Installing pinned kernel', 'pmssEnsurePinnedKernel', ['logmsg'], PMSS_UPDATE_STEP_CLASS_SOFT_FAIL],\n"
            ."    ['Applying boot defaults'", $source);
    }
}
