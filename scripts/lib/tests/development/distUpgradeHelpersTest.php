<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/distUpgrade.php';

class DistUpgradeHelpersTest extends TestCase
{
    public function testDetermineUpgradePath(): void
    {
        $this->assertEquals(['10','11'], \pmssDetermineUpgradePath('10'));
        $this->assertEquals(['11','12'], \pmssDetermineUpgradePath('11'));
        $this->assertEquals(['12','13'], \pmssDetermineUpgradePath('12'));
        $this->assertEquals([null,null], \pmssDetermineUpgradePath('13'));
        $this->assertEquals([null,null], \pmssDetermineUpgradePath('14'));
    }

    public function testCodenameForMajor(): void
    {
        $this->assertEquals('buster', \pmssCodenameForMajor('10'));
        $this->assertEquals('bullseye', \pmssCodenameForMajor('11'));
        $this->assertEquals('bookworm', \pmssCodenameForMajor('12'));
        $this->assertEquals('trixie', \pmssCodenameForMajor('13'));
        $this->assertEquals('', \pmssCodenameForMajor('99'));
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
    }

    public function testBootReadinessParsers(): void
    {
        $healthyMdstat = "md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/2] [UU]\n";
        $degradedMdstat = "md0 : active raid1 sda1[0] sdb1[1]\n      104320 blocks [2/1] [U_]\n";

        $this->assertTrue(!\pmssMdstatHasDegradedArrays($healthyMdstat));
        $this->assertTrue(\pmssMdstatHasDegradedArrays($degradedMdstat));

        $this->assertTrue(\pmssMdadmConfigHasArrayDefinitions("ARRAY /dev/md0 metadata=1.2 UUID=abc\n"));
        $this->assertTrue(!\pmssMdadmConfigHasArrayDefinitions("DEVICE partitions\nMAILADDR root\n"));

        $this->assertTrue(\pmssInitramfsBootDegradedEnabled("BOOT_DEGRADED=true\n"));
        $this->assertTrue(\pmssInitramfsBootDegradedEnabled("boot_degraded = true\n"));
        $this->assertTrue(!\pmssInitramfsBootDegradedEnabled("BOOT_DEGRADED=false\n"));
    }
}
