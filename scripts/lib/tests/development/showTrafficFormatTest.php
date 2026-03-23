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
        $out = shell_exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 3).'/showTraffic.php').' --help'
        );

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
