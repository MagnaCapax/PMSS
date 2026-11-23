<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** A rootless Docker launch reports OK even when dockerd dies during start-up; the next tick must say so. */
class UserDockerWatchdogLaunchTest extends TestCase
{
    /** Run the observer for a sequence of ticks and report each tick's log lines and marker state. */
    private function ticks(array $ticks, array $setup = [], array $preFiles = []): array
    {
        $root = $this->pmssMakeTempDir('pmss-docker-launch-');
        $script = <<<'PHP'
namespace DockerLaunchFixture;
function userDockerCollectPids($user, $debug, &$checkOk) { $checkOk = $GLOBALS['checkOk']; return $GLOBALS['pids']; }
function pmssUserLog($user, $message) { $GLOBALS['userLogs'][] = $user.': '.$message; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/docker/watchdog.php', 'DockerLaunchFixture');
        $script .= <<<'PHP'
[$root, $ticks, $setup, $preFiles] = json_decode(getenv('PMSS_TEST_DOCKER_LAUNCH'), true);
mkdir($root.'/runtime', 0700);
putenv('PMSS_RUNTIME_DIR='.$root.'/runtime');
foreach ($preFiles as $name => $body) file_put_contents($root.'/runtime/'.$name, $body);
PHP;
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).';'
            .'require_once '.var_export($this->pmssRepoPath('scripts/lib/user/watchdogLaunch.php'), true).';';
        $script .= <<<'PHP'
foreach ($setup as [$user, $age]) pmssUserWatchdogLaunchMarkerWrite($user, 'docker', time() - $age);
$results = [];
foreach ($ticks as [$user, $pids, $checkOk]) {
    $GLOBALS['pids'] = $pids;
    $GLOBALS['checkOk'] = $checkOk;
    $logs = $GLOBALS['userLogs'] = [];
    userDockerWatchdogLaunchObserve($user, static function ($line) use (&$logs) { $logs[] = $line; });
    $marker = pmssUserWatchdogLaunchMarkerRead($user, 'docker');
    $results[] = [$logs, $GLOBALS['userLogs'], $marker !== null];
}
$files = array_values(array_diff(scandir($root.'/runtime'), ['.', '..']));
$modes = array_map(static function ($file) use ($root) { return decoct(fileperms($root.'/runtime/'.$file) & 0777); }, $files);
$pre = [];
foreach ($preFiles as $name => $body) $pre[$name] = file_get_contents($root.'/runtime/'.$name);
echo json_encode([$results, $files, $modes, $pre]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_DOCKER_LAUNCH' => json_encode([$root, $ticks, $setup, $preFiles])]);
    }

    public function testFirstLaunchAfterBootIsSilentAndLeavesAMarker(): void
    {
        [$results, $files, $modes] = $this->ticks([['fixtur01', [], true]]);
        $this->assertSame([[], [], true], $results[0]);
        $this->assertSame(['watchdog-launch-fixtur01-docker.ts'], $files);
        $this->assertSame(['600'], $modes);
    }

    public function testDaemonAbsentOnTheTickAfterALaunchIsSurfaced(): void
    {
        [$results] = $this->ticks([['fixtur01', [], true], ['fixtur01', [], true], ['fixtur01', [], true]]);
        $this->assertSame([], $results[0][0]);
        foreach ([1, 2] as $tick) {
            $this->assertStringContainsString('[WARN] Docker watchdog for fixtur01: event=daemon_died_after_launch', implode("\n", $results[$tick][0]));
            $this->assertStringContainsString('fixtur01: [WARN] userDocker: daemon died after launch', implode("\n", $results[$tick][1]));
            $this->assertTrue($results[$tick][2], 'The relaunch must record a fresh marker, never suppress itself');
        }
    }

    public function testRunningDaemonClearsTheMarkerSoALaterDeathStartsQuiet(): void
    {
        [$results, $files] = $this->ticks([
            ['fixtur01', [], true], ['fixtur01', [4242], true], ['fixtur01', [], true],
        ]);
        $this->assertSame([[], [], false], $results[1]);
        $this->assertSame([[], [], true], $results[2], 'A death after a healthy tick is a first launch again');
        $this->assertSame(['watchdog-launch-fixtur01-docker.ts'], $files);
    }

    public function testUnreadableProcessListIsNoEvidenceEitherWay(): void
    {
        [$results] = $this->ticks([['fixtur01', [], true], ['fixtur01', [], false], ['fixtur01', [4242], false]]);
        $this->assertSame([[], [], true], $results[1], 'Failed process check keeps the marker and warns nothing');
        $this->assertSame([[], [], true], $results[2], 'Failed process check does not clear the marker even with pids listed');
    }

    public function testStaleMarkerFromBeforeADisableIsNotADeath(): void
    {
        [$results] = $this->ticks([['fixtur01', [], true]], [['fixtur01', 7200]]);
        $this->assertSame([[], [], true], $results[0]);
    }

    public function testRecentMarkerOfAnotherAccountDoesNotLeak(): void
    {
        [$results] = $this->ticks([['fixtur02', [], true], ['fixtur01', [], true]], [['fixtur01', 300]]);
        $this->assertSame([], $results[0][0], 'fixtur02 has no launch on record');
        $this->assertStringContainsString('Docker watchdog for fixtur01: event=daemon_died_after_launch', implode("\n", $results[1][0]));
    }

    public function testInvalidAccountNameNeverBecomesAStateFile(): void
    {
        [$results, $files] = $this->ticks([['../evil', [], true], ['../evil', [], true]]);
        $this->assertSame([[], [], false], $results[1]);
        $this->assertSame([], $files);
    }

    public function testRepeatedFailuresAreReportedOncePerTickAndEveryTickRelaunches(): void
    {
        $ticks = [];
        for ($i = 0; $i < 6; $i++) $ticks[] = ['fixtur01', [], true];
        [$results] = $this->ticks($ticks);
        $this->assertSame([], $results[0][0], 'The first launch stays silent');
        for ($i = 1; $i < 6; $i++) {
            $this->assertSame(1, count($results[$i][0]), 'Tick '.$i.' reports exactly one watchdog line');
            $this->assertSame(1, count($results[$i][1]), 'Tick '.$i.' reports exactly one account line');
            $this->assertTrue($results[$i][2], 'Tick '.$i.' leaves a fresh marker, so the relaunch is never suppressed');
        }
    }

    public function testAccountLogLineAppearsOnlyForTheAccountWhoseDaemonDied(): void
    {
        [$results] = $this->ticks([
            ['fixtur01', [], true], ['fixtur02', [4242], true],
            ['fixtur01', [], true], ['fixtur02', [4242], true],
        ]);
        $this->assertSame('fixtur01: [WARN] userDocker: daemon died after launch; the watchdog relaunches it every tick, run dockerd-rootless.sh in the foreground to see why', $results[2][1][0]);
        $this->assertSame(1, count($results[2][1]));
        $this->assertSame([[], [], false], $results[3], 'The healthy neighbour logs nothing');
    }

    public function testRunningAccountClearsOnlyItsOwnMarker(): void
    {
        [$results, $files] = $this->ticks([['fixtur01', [4242], true]], [['fixtur01', 60], ['fixtur02', 60]]);
        $this->assertSame([[], [], false], $results[0]);
        $this->assertSame(['watchdog-launch-fixtur02-docker.ts'], $files, 'fixtur02 keeps its marker untouched');
    }

    public function testUpgradedInstallWithNoMarkerIsSilentAndLeavesOtherWatchdogStateAlone(): void
    {
        $state = ['watchdog-timeout-fixtur01-docker.ts' => '1700000000', 'checkLighttpdInstances-config-fixtur01.json' => '{"n":1}'];
        [$results, $files, , $pre] = $this->ticks([['fixtur01', [], true], ['fixtur01', [], true]], [], $state);
        $this->assertSame([[], [], true], $results[0], 'No marker on disk is a first launch, not a death');
        $this->assertSame(1, count($results[1][0]), 'The next tick then behaves as on a fresh install');
        $this->assertSame($state, $pre, 'Existing watchdog state files are not modified');
        $this->assertTrue(in_array('watchdog-launch-fixtur01-docker.ts', $files, true));
        $this->assertSame(3, count($files));
    }

    public function testMarkerJustInsideTheThirtyMinuteWindowIsReportedAndOneJustOutsideIsNot(): void
    {
        [$inside] = $this->ticks([['fixtur01', [], true]], [['fixtur01', 1790]]);
        $this->assertSame(1, count($inside[0][0]));
        [$outside] = $this->ticks([['fixtur01', [], true]], [['fixtur01', 1810]]);
        $this->assertSame([], $outside[0][0]);
        $this->assertTrue($outside[0][2], 'The stale marker is replaced by a fresh one');
    }

    public function testWatchdogLoopObservesBeforeEveryLaunchAndAfterTheActionIsKnown(): void
    {
        $this->assertOrderedStrings([
            '$action = userDockerWatchdogAction($user, $logDockerMessage);',
            'if ($action === null) continue;',
            'userDockerWatchdogLaunchObserve($user, $logDockerMessage);',
            'runCommand($cmd, false, $logDockerMessage);',
        ], $this->pmssReadRepoFile('scripts/cron/checkRootlessDocker.php'));
    }
}
