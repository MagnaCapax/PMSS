<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/vnstatConfig.php';

class VnstatConfigSafetyTest extends TestCase
{
    public function testRefreshKeepsSettingsAndMetadata(): void
    {
        $path = $this->pmssWriteTempFile('vnstat-config',
            "RateUnit 1\nMaxBandwidth 1000\nBandwidthDetection 1\nForeign yes\n");
        chmod($path, 0640);
        $messages = [];
        $log = $this->pmssMakeArrayLogger($messages);

        $this->assertTrue(\pmssVnstatConfigRefresh($path, $log));
        $expected = "RateUnit 0\nMaxBandwidth 50000\nBandwidthDetection 0\nForeign yes\n";
        $this->assertSame($expected, file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertTrue(\pmssVnstatConfigRefresh($path, $log));
        $this->assertSame($expected, file_get_contents($path));
        $this->assertSame([], $messages);
    }

    public function testAbsentSettingsUseLegacyAppendShape(): void
    {
        foreach ([
            "Foreign yes\n" => "Foreign yes\n\nMaxBandwidth 50000\n\nBandwidthDetection 0\n",
            'MaxBandwidth 1000' => "MaxBandwidth 50000\nBandwidthDetection 0\n",
            'BandwidthDetection 1' => "BandwidthDetection 0\nMaxBandwidth 50000\n",
            'MaxBandwidth invalid' => "MaxBandwidth invalid\nMaxBandwidth 50000\n\nBandwidthDetection 0\n",
        ] as $input => $expected) {
            $path = $this->pmssWriteTempFile('vnstat-append', $input);
            $this->assertTrue(\pmssVnstatConfigRefresh($path, self::pmssMakeCallbacks(['log' => null])['log']));
            $this->assertSame($expected, file_get_contents($path));
        }
    }

    public function testSymlinkAndMissingTargetsAreRejected(): void
    {
        $root = $this->pmssMakeTempDir('pmss-vnstat-target-');
        $real = $this->pmssWriteRelativeFile($root, 'real.conf', "RateUnit 1\n");
        $link = $root.'/vnstat.conf';
        symlink($real, $link);
        $messages = [];
        $log = $this->pmssMakeArrayLogger($messages);
        $this->assertFalse(\pmssVnstatConfigRefresh($link, $log));
        $this->assertFalse(\pmssVnstatConfigRefresh($root.'/missing.conf', $log));
        $this->assertSame("RateUnit 1\n", file_get_contents($real));
        $this->assertSame(2, count($messages));
    }

    public function testFailedReplacementPreservesExistingConfig(): void
    {
        $original = "RateUnit 1\n";
        $path = $this->pmssWriteTempFile('vnstat-write', $original);
        $messages = [];
        $this->assertFalse(\pmssVnstatConfigRefresh($path, $this->pmssMakeArrayLogger($messages), self::pmssMakeCallbacks(['write' => false])['write']));
        $this->assertSame($original, file_get_contents($path));
        $this->pmssAssertMessagesContain($messages, 'skipping vnStat restart', 'expected write warning');
    }
}
