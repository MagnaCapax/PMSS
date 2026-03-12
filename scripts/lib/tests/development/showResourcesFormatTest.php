<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/show.php';

class ShowResourcesFormatTest extends TestCase
{
    public function testHelpIncludesCoreOptions(): void
    {
        $script = dirname(__DIR__, 3).'/showResources.php';
        $out = (string) shell_exec('php '.escapeshellarg($script).' --help 2>&1');

        $this->assertTrue(strpos($out, '--json') !== false);
        $this->assertTrue(strpos($out, '--show-missing') !== false);
        $this->assertTrue(strpos($out, '--user') !== false);
        $this->assertTrue(strpos($out, '--help') !== false);
    }

    public function testFormatBytesTiB(): void
    {
        $twoTiB = 2 * 1024 * 1024 * 1024 * 1024;
        $this->assertTrue(strpos(\pmssResourceFormatBytes($twoTiB), 'TiB') !== false);
    }

    public function testFormatCpuHours(): void
    {
        $oneHourNsec = 3600 * 1000000000;
        $this->assertEquals('1.0 hrs', \pmssResourceFormatCpuHours($oneHourNsec));
    }

    public function testFormatRamHoursDecimals(): void
    {
        $this->assertEquals('2.50 GB-hrs', \pmssResourceFormatRamHours(2.5));
    }

    public function testFormatOpsPerSecond(): void
    {
        $this->assertEquals('2.00', \pmssResourceFormatOpsPerSecond(7200, 3600));
    }

    public function testFormatOpsPerSecondZeroWindow(): void
    {
        $this->assertEquals('0.00', \pmssResourceFormatOpsPerSecond(7200, 0));
    }
}
