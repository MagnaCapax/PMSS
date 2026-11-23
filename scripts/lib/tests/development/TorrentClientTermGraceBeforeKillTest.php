<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/identity.php';

/**
 * Intent: scripts/lib/tests/development/TorrentClientTermGraceBeforeKillTest.php -- the qBittorrent port repair stops the
 * client with SIGTERM and a bounded wait before any SIGKILL, so in-flight writes are not cut and the config is not
 * rewritten by a still-exiting client.
 */
final class TorrentClientTermGraceBeforeKillTest extends TestCase
{
    public function testNativeClientStopTermsThenWaitsThenKillsOnlyWhenStillPresent(): void
    {
        foreach (array('exits' => array(array(1), array(1), array()), 'hangs' => array(array(1), array(1), array(1), array(1))) as $case => $polls) {
            $signals = array();
            \pmssTorrentClientNativeStopWithGrace('fixtur01', 'qbittorrent', array(
                'stop' => static function (int $signal) use (&$signals): int { $signals[] = $signal; return 1; },
                'remaining' => static function () use (&$polls): array { return count($polls) > 1 ? array_shift($polls) : $polls[0]; },
                'pause' => static function (): void {},
                'attempts' => 2,
            ));
            $this->assertSame($case === 'exits' ? array(15) : array(15, 9), $signals, $case);
        }
    }

    public function testPortRepairUsesTheGraceHelper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/torrentPort.php',
            array("pmssTorrentClientNativeStopWithGrace(\$user, 'qbittorrent')"), array("pmssTorrentClientNativeStop(\$user, 'qbittorrent', 9)"));
    }
}
