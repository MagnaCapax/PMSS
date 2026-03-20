<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CronInlineCharacterizationTest extends TestCase
{
    public function testBootTuningKeepsFileWritesInline(): void
    {
        $src = $this->readRuntimeFile('scripts/lib/update/systemPrep.php');
        $wrapperNeedle = '$write'.'Target = static function';

        $this->assertTrue(
            strpos($src, $wrapperNeedle) === false,
            'pmssEnsureBootTuning() should keep its two file writes inline rather than via a local wrapper'
        );
        $this->assertStringContainsString('[$scriptTarget, $scriptRaw, 0755, \'Boot tuning script\']', $src);
        $this->assertStringContainsString('[$serviceTarget, $serviceRaw, 0644, \'Boot tuning service\']', $src);
        $this->assertStringContainsString('$log(\'Installed \'.$label.\' at \'.$path);', $src);
        $this->assertStringContainsString("@rename(\$tmp, \$path)", $src);
    }

    public function testQbittorrentWatchdogKeepsStartSequenceInline(): void
    {
        $src = $this->readRuntimeFile('scripts/cron/checkQbittorrentInstances.php');
        $wrapperNeedle = '$start'.'Qbittorrent = static function';

        $this->assertTrue(
            strpos($src, $wrapperNeedle) === false,
            'checkQbittorrentInstances.php should keep the qBittorrent start sequence inline'
        );
        $this->assertStringContainsString('Start qBittorrent for user: {$thisUser}', $src);
        $this->assertStringContainsString('nohup qbittorrent-nox -d >> /dev/null 2>&1 &', $src);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'qbittorrent-nox start requested');", $src);
    }

    public function testRcloneWatchdogKeepsStartSequenceInline(): void
    {
        $src = $this->readRuntimeFile('scripts/cron/checkRcloneInstances.php');
        $wrapperNeedle = '$start'.'Rclone = static function';

        $this->assertTrue(
            strpos($src, $wrapperNeedle) === false,
            'checkRcloneInstances.php should keep the rclone start sequence inline'
        );
        $this->assertStringContainsString('Start rclone for user: {$thisUser}', $src);
        $this->assertStringContainsString('--rc-web-gui --rc-addr 127.0.0.1:{$port}', $src);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'rclone start requested');", $src);
    }

    public function testDelugeWatchdogKeepsStartSequencesInline(): void
    {
        $src = $this->readRuntimeFile('scripts/cron/checkDelugeInstances.php');
        $delugedNeedle = '$start'.'Deluged = static function';
        $webNeedle = '$start'.'DelugeWeb = static function';

        $this->assertTrue(
            strpos($src, $delugedNeedle) === false,
            'checkDelugeInstances.php should keep the deluged start sequence inline'
        );
        $this->assertTrue(
            strpos($src, $webNeedle) === false,
            'checkDelugeInstances.php should keep the deluge-web start sequence inline'
        );
        $this->assertStringContainsString('Start deluged for user: {$thisUser}', $src);
        $this->assertStringContainsString('Start deluge-web for user: {$thisUser}', $src);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'deluged start requested');", $src);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'deluge-web start requested');", $src);
    }

    public function testLighttpdWatchdogKeepsRestartSequenceInline(): void
    {
        $src = $this->readRuntimeFile('scripts/cron/checkLighttpdInstances.php');
        $wrapperNeedle = '$restart'.'Lighttpd = static function';

        $this->assertTrue(
            strpos($src, $wrapperNeedle) === false,
            'checkLighttpdInstances.php should keep the lighttpd restart sequence inline'
        );
        $this->assertStringContainsString('Killing (if any) lighttpd for user: {$thisUser}', $src);
        $this->assertStringContainsString('killall -15 -u {$thisUser} lighttpd; killall -15 -u {$thisUser} php-cgi; sleep 5; killall -9 -u {$thisUser} lighttpd; killall -9 -u {$thisUser} php-cgi;', $src);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'lighttpd restart requested');", $src);
        $this->assertStringContainsString('if ($socketError || empty($instancesLighttpd)) {', $src);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'lighttpd start requested');", $src);
    }

    private function readRuntimeFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 4).'/'.$relativePath;
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);
        return (string) $src;
    }
}
