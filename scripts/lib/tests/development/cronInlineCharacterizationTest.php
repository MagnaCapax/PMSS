<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/RepoFileReadTrait.php';

class CronInlineCharacterizationTest extends TestCase
{
    use RepoFileReadTrait;

    public function testBootTuningKeepsFileWritesInline(): void
    {
        $src = $this->readRepoFile('scripts/lib/update/systemPrep.php');
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
        $src = $this->readRepoFile('scripts/cron/checkQbittorrentInstances.php');
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
        $src = $this->readRepoFile('scripts/cron/checkRcloneInstances.php');
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
        $src = $this->readRepoFile('scripts/cron/checkDelugeInstances.php');
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

    public function testWatchdogsKeepSuspensionAndStartUserLogMessages(): void
    {
        $lighttpdSrc = $this->readRepoFile('scripts/cron/checkLighttpdInstances.php');
        $qbittorrentSrc = $this->readRepoFile('scripts/cron/checkQbittorrentInstances.php');
        $rcloneSrc = $this->readRepoFile('scripts/cron/checkRcloneInstances.php');
        $delugeSrc = $this->readRepoFile('scripts/cron/checkDelugeInstances.php');

        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'lighttpd stopped due to suspension');", $lighttpdSrc);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'lighttpd start requested');", $lighttpdSrc);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'qbittorrent-nox stopped due to suspension');", $qbittorrentSrc);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'qbittorrent-nox start requested');", $qbittorrentSrc);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'rclone stopped due to suspension');", $rcloneSrc);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'rclone start requested');", $rcloneSrc);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'deluge stopped due to suspension');", $delugeSrc);
        $this->assertStringContainsString("pmssUserLog(\$thisUser, 'deluged start requested');", $delugeSrc);
    }

    public function testLighttpdWatchdogKeepsRestartSequenceInline(): void
    {
        $src = $this->readRepoFile('scripts/cron/checkLighttpdInstances.php');
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

}
