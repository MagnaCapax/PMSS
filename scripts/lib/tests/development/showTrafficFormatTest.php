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
    }
}

