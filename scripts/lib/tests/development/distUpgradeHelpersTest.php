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
}
