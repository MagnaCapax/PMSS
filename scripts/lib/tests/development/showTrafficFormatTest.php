<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/showTraffic.php';

class ShowTrafficFormatTest extends TestCase
{
    public function testFormatTrafficAmountCharacterizationAcrossUnits(): void
    {
        $twoTiBInMiB = 2 * 1024 * 1024;
        foreach ([[500, '500MiB'], [2000, '1.95GiB'], [$twoTiBInMiB, '2TiB']] as $case) {
            $this->assertEquals($case[1], \pmssTrafficFormatAmount($case[0]));
        }
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
        $this->assertStringContainsAllStrings(['--json', '--show-missing', '--extended', '--sort', '--color', '--no-color'], $out);
    }

    public function testShowTrafficUsesSharedManagedUsersParser(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/showTraffic.php', "pmssListManagedUsersResult(__DIR__.'/listUsers.php')");
        $this->pmssAssertRepoFileNotContainsString('scripts/showTraffic.php', "exec(escapeshellarg(__DIR__.'/listUsers.php')");
        $this->pmssAssertRepoFileContainsString('scripts/showTraffic.php', "array_map('pmssTrafficFormatAmount', \$data['raw'])");
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
