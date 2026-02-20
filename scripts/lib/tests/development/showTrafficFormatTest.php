<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/showTraffic.php';

class ShowTrafficFormatTest extends TestCase
{
    public function testFormatTrafficAmountMiB(): void
    {
        $this->assertEquals('500MiB', \formatTrafficAmount(500));
    }

    public function testFormatTrafficAmountGiB(): void
    {
        $this->assertEquals('1.95GiB', \formatTrafficAmount(2000));
    }

    public function testFormatTrafficAmountTiB(): void
    {
        $twoTiBInMiB = 2 * 1024 * 1024;
        $this->assertEquals('2TiB', \formatTrafficAmount($twoTiBInMiB));
    }

    public function testHelpIncludesJsonOption(): void
    {
        ob_start();
        try {
            \pmssShowTrafficPrintHelp();
            $out = (string) ob_get_clean();
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        $this->assertTrue(strpos($out, '--json') !== false);
        $this->assertTrue(strpos($out, '--show-missing') !== false);
        $this->assertTrue(strpos($out, '--extended') !== false);
        $this->assertTrue(strpos($out, '--sort') !== false);
        $this->assertTrue(strpos($out, '--color') !== false);
        $this->assertTrue(strpos($out, '--no-color') !== false);
    }

    public function testFormatRateDisplay(): void
    {
        $cases = [
            [0.0, '0.00MiB/s'],
            [12.345, '12.35MiB/s'],
            [999.99, '999.99MiB/s'],
            [1000.0, '0.98GiB/s'],
            [1024.0, '1.00GiB/s'],
            [2048.0, '2.00GiB/s'],
        ];
        foreach ($cases as $case) {
            $this->assertEquals($case[1], \pmssShowTrafficFormatRateDisplay($case[0]));
        }
    }

    public function testRenderBar(): void
    {
        $cases = [
            [0, '[----------]'],
            [1, '[----------]'],
            [10, '[#---------]'],
            [50, '[#####-----]'],
            [80, '[########--]'],
            [100, '[##########]'],
            [150, '[##########]'],
        ];
        foreach ($cases as $case) {
            $this->assertEquals($case[1], \pmssShowTrafficRenderBar($case[0]));
        }
    }

    public function testSplitLocalnetUser(): void
    {
        $cases = [
            ['alice', ['alice', false]],
            ['bob-localnet', ['bob', true]],
            ['carol-localnet-localnet', ['carol-localnet', true]],
            ['dave-localnetx', ['dave-localnetx', false]],
            ['eve-localnet', ['eve', true]],
        ];
        foreach ($cases as $case) {
            $this->assertEquals($case[1], \pmssShowTrafficSplitLocalnetUser($case[0]));
        }
    }
}
