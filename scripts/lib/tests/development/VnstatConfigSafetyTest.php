<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/apps/vnstatConfig.php';

class VnstatConfigSafetyTest extends TestCase
{
    public function testRefreshKeepsSettingsAndMetadata(): void
    {
        $path = $this->pmssMakeTempDir('pmss-vnstat-config-').'/vnstat.conf';
        file_put_contents($path, "RateUnit 1\nMaxBandwidth 1000\nBandwidthDetection 1\nForeign yes\n");
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
        $path = $this->pmssMakeTempDir('pmss-vnstat-append-').'/vnstat.conf';
        file_put_contents($path, "Foreign yes\n");
        $this->assertTrue(\pmssVnstatConfigRefresh($path, static function (string $message): void {}));
        $this->assertSame("Foreign yes\n\nMaxBandwidth 50000\n\nBandwidthDetection 0\n", file_get_contents($path));
    }

    public function testSymlinkAndMissingTargetsAreRejected(): void
    {
        $root = $this->pmssMakeTempDir('pmss-vnstat-target-');
        $real = $root.'/real.conf';
        $link = $root.'/vnstat.conf';
        file_put_contents($real, "RateUnit 1\n");
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
        $path = $this->pmssMakeTempDir('pmss-vnstat-write-').'/vnstat.conf';
        $original = "RateUnit 1\n";
        file_put_contents($path, $original);
        $messages = [];
        $this->assertFalse(\pmssVnstatConfigRefresh($path, $this->pmssMakeArrayLogger($messages), static function (): bool { return false; }));
        $this->assertSame($original, file_get_contents($path));
        $this->pmssAssertMessagesContain($messages, 'skipping vnStat restart', 'expected write warning');
    }
}
