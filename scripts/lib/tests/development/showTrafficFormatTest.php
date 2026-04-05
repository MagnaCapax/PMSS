<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/showTraffic.php';

class ShowTrafficFormatTest extends TestCase
{
    public function testFormatTrafficAmountMiB(): void
    {
        $this->assertEquals('500MiB', \pmssTrafficFormatAmount(500));
    }

    public function testFormatTrafficAmountGiB(): void
    {
        $this->assertEquals('1.95GiB', \pmssTrafficFormatAmount(2000));
    }

    public function testFormatTrafficAmountTiB(): void
    {
        $twoTiBInMiB = 2 * 1024 * 1024;
        $this->assertEquals('2TiB', \pmssTrafficFormatAmount($twoTiBInMiB));
    }

    public function testTrafficAmountFormatterCharacterizationKeepsLegacyThresholds(): void
    {
        $this->assertEquals([
            '0MiB',
            '1024MiB',
            '1GiB',
            '1024GiB',
            '1TiB',
        ], [
            \pmssTrafficFormatAmount(0),
            \pmssTrafficFormatAmount(1024),
            \pmssTrafficFormatAmount(1025),
            \pmssTrafficFormatAmount(1024 * 1024),
            \pmssTrafficFormatAmount((1024 * 1024) + 1),
        ]);
    }

    public function testHelpIncludesJsonOption(): void
    {
        $out = $this->pmssRunRepoPhpScript('scripts/showTraffic.php', ['--help'], [], '');

        $this->assertTrue(is_string($out));
        $this->assertTrue(strpos($out, '--json') !== false);
        $this->assertTrue(strpos($out, '--show-missing') !== false);
        $this->assertTrue(strpos($out, '--extended') !== false);
        $this->assertTrue(strpos($out, '--sort') !== false);
        $this->assertTrue(strpos($out, '--color') !== false);
        $this->assertTrue(strpos($out, '--no-color') !== false);
    }

    public function testShowTrafficUsesSharedManagedUsersParser(): void
    {
        $source = $this->pmssReadRepoFile('scripts/showTraffic.php');

        $this->assertStringContainsString("pmssListManagedUsersResult(__DIR__.'/listUsers.php')", $source);
        $this->pmssAssertStringNotContainsString("exec(escapeshellarg(__DIR__.'/listUsers.php')", $source);
        $this->assertStringContainsString("array_map('pmssTrafficFormatAmount', \$data['raw'])", $source);
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
}
