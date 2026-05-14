<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/distUpgrade.php';

class DistUpgradeHelpersTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    public function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tmpDir', 'pmss-dist-upgrade-helpers-');
    }

    public function testResolveTargetVersionAcceptsNumbersAndCodenames(): void
    {
        $this->assertEquals('10', \pmssResolveTargetVersion('10'));
        $this->assertEquals('10', \pmssResolveTargetVersion('buster'));
        $this->assertEquals('11', \pmssResolveTargetVersion('bullseye'));
        $this->assertEquals('12', \pmssResolveTargetVersion('Bookworm'));
        $this->assertEquals('13', \pmssResolveTargetVersion('TRIXIE'));
        $this->assertEquals('', \pmssResolveTargetVersion('jessie'));
        $this->assertEquals('', \pmssResolveTargetVersion('stretch'));
        $this->assertEquals('', \pmssResolveTargetVersion('8'));
        $this->assertEquals('', \pmssResolveTargetVersion('9'));
        $this->assertEquals('', \pmssResolveTargetVersion('10 '));
        $this->assertEquals('', \pmssResolveTargetVersion(''));
        $this->assertEquals('', \pmssResolveTargetVersion('nonesuch'));
    }

    public function testResolveDistUpgradeStepHonorsMaximum(): void
    {
        $plan = \pmssResolveDistUpgradeStep('10', '12');
        $this->assertEquals('upgrade', $plan['action']);
        $this->assertEquals('10', $plan['from']);
        $this->assertEquals('11', $plan['to']);
        $this->assertStringContainsString('Requested maximum is 12', $plan['message']);

        $plan = \pmssResolveDistUpgradeStep('11', '13');
        $this->assertEquals('upgrade', $plan['action']);
        $this->assertEquals('11', $plan['from']);
        $this->assertEquals('12', $plan['to']);

        $plan = \pmssResolveDistUpgradeStep('11', '11');
        $this->assertEquals('noop', $plan['action']);
        $this->assertEquals(null, $plan['to']);
        $this->assertStringContainsString('No dist-upgrade required', $plan['message']);

        $plan = \pmssResolveDistUpgradeStep('12', '11');
        $this->assertEquals('error', $plan['action']);
        $this->assertEquals(null, $plan['to']);
        $this->assertStringContainsString('Safety halt', $plan['message']);

        $plan = \pmssResolveDistUpgradeStep('13', '13');
        $this->assertEquals('noop', $plan['action']);
        $this->assertEquals(null, $plan['to']);
        $this->assertStringContainsString('No dist-upgrade required', $plan['message']);

        $plan = \pmssResolveDistUpgradeStep('13', '12');
        $this->assertEquals('error', $plan['action']);
        $this->assertEquals(null, $plan['to']);
        $this->assertStringContainsString('Safety halt', $plan['message']);

        $plan = \pmssResolveDistUpgradeStep('14', '13');
        $this->assertEquals('error', $plan['action']);
        $this->assertEquals('14', $plan['from']);
        $this->assertEquals(null, $plan['to']);
        $this->assertStringContainsString('Safety halt', $plan['message']);
    }

    public function testBootReadinessParsers(): void
    {
        $cases = [
            ['', false],
            ["Personalities : [raid1]\nunused devices: <none>\n", false],
            ["md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/2] [UU]\n", false],
            ["md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/1] [U_]\n", true],
            ["md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/1] [_U]\n", true],
            ["md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/2] [UU]\nmd1 : active raid1 sdc1[0] sdd1[1]\n      104320 blocks [2/1] [U_]\n", true],
        ];

        foreach ($cases as [$mdstat, $expected]) {
            $this->assertEquals($expected, \pmssMdstatHasDegradedArrays($mdstat));
        }
    }

    public function testVerifyDistUpgradeBootReadinessLogsHealthyConfigs(): void
    {
        $output = $this->captureBootReadinessOutput(
            "md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/2] [UU]\n",
            str_repeat('menuentry test\n', 100),
            "ARRAY /dev/md0 metadata=1.2 UUID=abc\n",
            "BOOT_DEGRADED=true\n"
        );

        $this->assertStringContainsString('[SKIP] dist-upgrade: RAID arrays appear healthy', $output);
        $this->assertStringContainsString('[SKIP] dist-upgrade: grub config present', $output);
        $this->assertStringContainsString('[SKIP] dist-upgrade: mdadm ARRAY definitions found', $output);
        $this->assertStringContainsString('[SKIP] dist-upgrade: BOOT_DEGRADED=true is configured', $output);
    }

    public function testVerifyDistUpgradeBootReadinessWarnsForUnsafeRaidBootConfig(): void
    {
        $output = $this->captureBootReadinessOutput(
            "md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/1] [U_]\n",
            str_repeat('menuentry test\n', 100),
            "DEVICE partitions\nMAILADDR root\n",
            "BOOT_DEGRADED=false\n"
        );

        $this->assertStringContainsString('degraded RAID array detected', $output);
        $this->assertStringContainsString('lacks ARRAY definitions', $output);
        $this->assertStringContainsString('missing BOOT_DEGRADED=true', $output);
    }

    private function captureBootReadinessOutput(
        string $mdstat,
        string $grub,
        string $mdadmConfig,
        string $initramfsMdadm
    ): string {
        $mdstatPath = $this->tmpDir.'/mdstat';
        $grubPath = $this->tmpDir.'/grub.cfg';
        $mdadmConfigPath = $this->tmpDir.'/mdadm.conf';
        $initramfsMdadmPath = $this->tmpDir.'/initramfs-mdadm';

        file_put_contents($mdstatPath, $mdstat);
        file_put_contents($grubPath, $grub);
        file_put_contents($mdadmConfigPath, $mdadmConfig);
        file_put_contents($initramfsMdadmPath, $initramfsMdadm);

        list(, $output) = $this->pmssCaptureStdout(function () use ($mdstatPath, $grubPath, $mdadmConfigPath, $initramfsMdadmPath): void {
            \pmssVerifyDistUpgradeBootReadiness($mdstatPath, $grubPath, $mdadmConfigPath, $initramfsMdadmPath);
        });

        return $output;
    }
}
