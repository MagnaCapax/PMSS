<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Panel starts run under php-cgi, and a lighttpd restart terminates that process group. The Hermes setup,
 * the rclone web GUI and the Media Stack installer stay in the foreground, so each launches with setsid
 * like the Transmission daemon (TransmissionLaunchSessionTest proves the prefix keeps the reported pid).
 */
final class PanelBackgroundStartSessionTest extends TestCase
{
    public function testForegroundPanelStartsLeaveThePhpCgiSession(): void
    {
        $www = dirname(__DIR__, 4).'/etc/skel/www/';
        foreach (['hermesAgent.php', 'rclone.php', 'userMediaStackPanel.php'] as $file) {
            $source = (string) preg_replace('/^\s*(\/\/|\*|\/\*).*$/m', '', (string) file_get_contents($www.$file));
            $this->assertTrue(substr_count($source, 'nohup ') > 0, $file.': has a background start');
            $this->assertSame(0, preg_match_all('/nohup (?!\/usr\/bin\/setsid )\S/', $source), $file.': every start uses setsid');
        }
    }
}
