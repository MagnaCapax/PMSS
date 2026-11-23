<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/torrentClients/identity.php';

/**
 * Intent: scripts/lib/tests/development/TorrentClientTermGraceBeforeKillTest.php -- the qBittorrent and deluge-web port repairs stop the
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

    /** Process table for the deluge-web case: pid 11 is deluged, pid 12 is deluge-web, both owned by the account. */
    private function delugeIdentityOps(array &$alive, array &$signals, int $diesOnSignal): array
    {
        $names = array(11 => 'deluged', 12 => 'deluge-web');
        return array(
            'account' => static function (string $user): array { return array('uid' => 1000); },
            'pids' => static function (string $user, string $name) use (&$alive): array { return array_keys($alive); },
            'namespace' => static function (int $pid): string { return 'mnt:[host]'; },
            'read' => static function (string $path) use ($names) {
                if (substr($path, -5) === '/comm') return $names[(int) basename(dirname($path))]."\n";
                return substr($path, -7) === '/status' ? "Uid:\t1000 1000 1000 1000\n" : false;
            },
            'exe' => static function (int $pid) use ($names): string { return '/home/.pmss/clients/deluge/2.1.1/bin/'.$names[$pid]; },
            'signal' => static function (int $pid, int $value) use (&$alive, &$signals, $diesOnSignal): bool {
                $signals[] = array($pid, $value);
                if ($value === $diesOnSignal) unset($alive[$pid]);
                return true;
            },
            'pause' => static function (): void {},
            'attempts' => 2,
        );
    }

    public function testDelugeWebStopTermsFirstKillsOnlyIfStillRunningAndNeverTouchesDeluged(): void
    {
        // deluge-web exits on SIGTERM: no SIGKILL. It ignores SIGTERM: SIGKILL follows. deluged (pid 11) is never signalled.
        foreach (array(15 => array(array(12, 15)), 9 => array(array(12, 15), array(12, 9))) as $diesOnSignal => $expected) {
            $alive = array(11 => true, 12 => true);
            $signals = array();
            \pmssDelugeWebStopWithGrace('fixtur01', $this->delugeIdentityOps($alive, $signals, $diesOnSignal));
            $this->assertSame($expected, $signals, 'dies on '.$diesOnSignal);
            $this->assertSame(array(11), array_keys($alive));
        }
    }

    public function testDelugePortRepairUsesTheDelugeWebGraceStop(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/torrentPort.php',
            array("pmssDelugeWebStopWithGrace(\$user)"), array("pmssTorrentClientNativeStop(\$user, 'deluge', 9"));
    }
}
