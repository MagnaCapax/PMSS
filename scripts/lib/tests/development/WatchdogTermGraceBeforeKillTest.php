<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';

/**
 * Intent: scripts/lib/tests/development/WatchdogTermGraceBeforeKillTest.php -- the watchdog gives processes SIGTERM and a
 * bounded wait before any SIGKILL, so a torrent daemon is not cut off mid-write.
 */
final class WatchdogTermGraceBeforeKillTest extends TestCase
{
    public function testProcessesThatExitAfterSigtermAreNeverKilled(): void
    {
        $signals = array();
        $alive = array('deluged' => 2, 'deluge-web' => 1); // polls left before each process has exited
        \pmssUserWatchdogTerminateProcessesWithGrace('fixtur01', array('deluged', 'deluge-web'), array(
            'terminate' => static function ($user, $names, $signal) use (&$signals): void { $signals[] = array($signal, $names); },
            'running' => static function ($user, $name) use (&$alive): bool { return $alive[$name]-- > 0; },
            'pause' => static function (): void {},
        ));
        $this->assertSame(array(array(15, array('deluged', 'deluge-web'))), $signals);
    }

    public function testOnlyProcessesStillRunningAfterTheGraceAreKilled(): void
    {
        $signals = array();
        \pmssUserWatchdogTerminateProcessesWithGrace('fixtur01', array('deluged', 'deluge-web'), array(
            'terminate' => static function ($user, $names, $signal) use (&$signals): void { $signals[] = array($signal, $names); },
            'running' => static function ($user, $name): bool { return $name === 'deluged'; },
            'pause' => static function (): void {},
            'attempts' => 3,
        ));
        $this->assertSame(array(array(15, array('deluged', 'deluge-web')), array(9, array('deluged'))), $signals);
    }

    public function testGraceWaitIsBounded(): void
    {
        $pauses = 0;
        \pmssUserWatchdogTerminateProcessesWithGrace('fixtur01', array('deluged'), array(
            'terminate' => static function (): void {},
            'running' => static function (): bool { return true; },
            'pause' => static function () use (&$pauses): void { $pauses++; },
            'attempts' => 5,
        ));
        $this->assertSame(5, $pauses);
    }

    public function testDelugeWatchdogFallbackUsesTheGraceHelper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/cron/checkDelugeInstances.php',
            array("pmssUserWatchdogTerminateProcessesWithGrace(\$thisUser, ['deluged', 'deluge-web'])"),
            array("pmssUserWatchdogTerminateProcesses(\$thisUser, ['deluged', 'deluge-web'], 9)"));
    }
}
