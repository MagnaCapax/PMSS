<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/rtorrent/process.php';
require_once dirname(__DIR__, 2).'/testing/filesystem.php';

/** Lock processReadiness.php using a virtual clock and no real sleeps or /proc reads. */
final class RtorrentProcessReadinessCharacterizationTest extends TestCase
{
    private function readinessTrace(array $case): array
    {
        $script = <<<'PHP'
namespace RtorrentReadinessFixture;
function microtime($asFloat) { return $GLOBALS['now']; }
function usleep($micros) { $GLOBALS['events'][] = ['sleep', $micros]; $GLOBALS['now'] += $micros / 1000000; }
function file_get_contents($path) { $GLOBALS['events'][] = ['read', $path]; return $GLOBALS['case']['uptime']; }
function crc32($user) { $GLOBALS['events'][] = ['hash', $user]; return $GLOBALS['case']['hash']; }
PHP;
        $script .= 'require_once '.var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).';';
        foreach (['rtorrentProcessPidParse', 'rtorrentProcessNormalizePids', 'rtorrentProcessWaitForStablePids',
            'rtorrentProcessRecentReboot', 'rtorrentProcessStaggerDelay'] as $name) {
            $script .= \pmssTestingFunctionExport($name, 'RtorrentReadinessFixture');
        }
        $script .= <<<'PHP'
$GLOBALS['case'] = json_decode(getenv('PMSS_TEST_PROCESS_READINESS'), true);
$GLOBALS['events'] = [];
$GLOBALS['now'] = 100.0;
$case = $GLOBALS['case'];
try {
    if ($case['action'] === 'stable') {
        $result = rtorrentProcessWaitForStablePids(static function () {
            $pids = array_shift($GLOBALS['case']['samples']);
            $GLOBALS['events'][] = ['pids', $pids];
            if ($pids === 'throw') throw new \RuntimeException('provider failure');
            return $pids;
        }, $case['startup'], $case['stability'], $case['poll']);
    } elseif ($case['action'] === 'reboot') {
        $result = rtorrentProcessRecentReboot($case['threshold']);
    } else {
        $result = rtorrentProcessStaggerDelay('fixtur01', $case['max']);
    }
} catch (\Throwable $error) {
    $result = [get_class($error), $error->getMessage()];
}
echo json_encode([$result, $GLOBALS['events']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_PROCESS_READINESS' => json_encode($case)]);
    }

    public function testStartupAndSurvivalKeepProbeAndSleepOrder(): void
    {
        foreach ([
            [[[17], [17]], 3.0, 2.0, 1000000, true,
                [['pids', [17]], ['sleep', 1000000], ['sleep', 1000000], ['pids', [17]]]],
            [[[], ['0017', 17, 0], [17]], 3.0, 2.0, 1000000, true,
                [['pids', []], ['sleep', 1000000], ['pids', ['0017', 17, 0]],
                    ['sleep', 1000000], ['sleep', 1000000], ['pids', [17]]]],
            [[[], [], []], 2.0, 2.0, 1000000, false,
                [['pids', []], ['sleep', 1000000], ['pids', []], ['sleep', 1000000], ['pids', []]]],
            [[[17], [18]], 0.0, 0.0, 1000000, false, [['pids', [17]], ['pids', [18]]]],
            [[[17], [17]], -1.0, -1.0, 0, true, [['pids', [17]], ['pids', [17]]]],
            [[[]], 0.0, 1.0, 1000000, false, [['pids', []]]],
            [[[], [17], [17]], 1.0, 0.0, 1, true,
                [['pids', []], ['sleep', 1000], ['pids', [17]], ['pids', [17]]]],
            [[[], 'throw'], 1.0, 0.0, 1000000, ['RuntimeException', 'provider failure'],
                [['pids', []], ['sleep', 1000000], ['pids', 'throw']]],
        ] as [$samples, $startup, $stability, $poll, $expected, $events]) {
            $this->assertSame([$expected, $events], $this->readinessTrace([
                'action' => 'stable', 'samples' => $samples, 'startup' => $startup,
                'stability' => $stability, 'poll' => $poll,
            ]));
        }
    }

    public function testRebootBoundaryKeepsLegacyNumericInterpretation(): void
    {
        foreach ([[false, 600, false], ['', 600, false], ['0 12', 600, false], ['-1 12', 600, false],
            ['1 12', 600, true], ['599.9 12', 600, true], ['600 12', 600, false], ['601 12', 600, false],
            ['invalid', 600, false], [" 12.5 66\n", 600, true], ['1 12', 0, false]] as [$uptime, $threshold, $expected]) {
            $this->assertSame([$expected, [['read', '/proc/uptime']]],
                $this->readinessTrace(['action' => 'reboot', 'uptime' => $uptime, 'threshold' => $threshold]));
        }
    }

    public function testStaggeringKeepsSignedHashMaskAndInclusiveDelayRange(): void
    {
        foreach ([[-2147483648, 0], [-1, 7], [0, 0], [1, 1], [2147483647, 7], [4294967295, 5]] as [$hash, $delay]) {
            $this->assertSame([$delay, [['hash', 'fixtur01']]],
                $this->readinessTrace(['action' => 'stagger', 'hash' => $hash, 'max' => 9]));
        }
        $this->assertSame([0, [['hash', 'fixtur01']]],
            $this->readinessTrace(['action' => 'stagger', 'hash' => 123, 'max' => 0]));
    }
}
