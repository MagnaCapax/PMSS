<?php
/** Verify mounted-device md status completeness with intercepted observations. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StorageBenchmarkMdStatusSafetyTest extends TestCase
{
    /** Load the inspector in isolation; no command, sysfs read or device probe runs. */
    private function fixtureScript(): string
    {
        $script = "namespace BenchmarkMdStatusFixture;\nrequire_once ".
            var_export($this->pmssRepoPath('scripts/lib/storageBenchmark.php'), true).";\n";
        $script .= <<<'PHP'
function file_get_contents($path) {
    $GLOBALS['reads'][] = $path;
    return $GLOBALS['metadata'][basename($path)];
}
function is_dir($path) { return true; }
function file_exists($path) { return false; }
$resolve = static function () {
    return storageBenchmarkHomeDeviceResolve('/unused', '/dev/md0', '/fixture/sys',
        static function ($path) { return true; },
        static function ($path) { return $path; },
        static function ($path) { return 1048576; });
};
PHP;
        return $script.$this->pmssInlinePhpLibraryInNamespace(
            'scripts/lib/storageBenchmark/homeDeviceInspection.php', 'BenchmarkMdStatusFixture');
    }

    public function testEmptyOrFailedMdStatusReadsCannotCertifyInspection(): void
    {
        $script = $this->fixtureScript().<<<'PHP'
$cases = [];
// Exercise both present counters and the nonredundant missing-counter fallback.
foreach (['raid10', 'raid0', 'linear'] as $level) {
    foreach (['0', '1', false] as $degraded) {
        foreach (['array_state', 'level'] as $field) {
            foreach ([false, '', ' ', "\n", "\t\r\n", " \t\n \r"] as $raw) {
                $GLOBALS['metadata'] = array_replace(['rotational' => "0\n",
                    'array_state' => "clean\n", 'level' => $level."\n", 'degraded' => $degraded], [$field => $raw]);
                $GLOBALS['reads'] = [];
                $cases[] = [$resolve(), $GLOBALS['reads']];
            }
        }
    }
}
echo json_encode($cases);
PHP;
        $result = ['device' => '/dev/md0', 'rota' => 0, 'size' => 1048576,
            'mode' => 'home-device', 'ok' => false, 'error' => 'unable to read md array status'];
        $reads = ['/fixture/sys/md0/queue/rotational', '/fixture/sys/md0/md/array_state',
            '/fixture/sys/md0/md/level', '/fixture/sys/md0/md/degraded'];
        $this->assertSame(array_fill(0, 108, [$result, $reads]), $this->pmssRunInlinePhpJson($script));
    }

    public function testNonemptyMetadataKeepsNormalizationAndCounterPolicy(): void
    {
        $script = $this->fixtureScript().<<<'PHP'
$cases = [];
// Keep status observations open to future kernel values, including literal zero.
foreach ([['clean', 'raid10', '0001'], ['active', 'raid1', '0'], ['readonly', 'raid5', '1'],
    ['read-auto', 'raid0', false], ['active-idle', 'linear', false],
    ['future-state', 'future-level', '0'], ['0', '0', '0'], ['clean', 'raid10', false]] as $row) {
    $GLOBALS['metadata'] = ['rotational' => "0\n", 'array_state' => " \t".$row[0]."\r\n",
        'level' => " \t".$row[1]."\r\n", 'degraded' => $row[2]];
    $cases[] = $resolve();
}
echo json_encode($cases);
PHP;
        $base = ['device' => '/dev/md0', 'rota' => 0, 'size' => 1048576, 'mode' => 'home-device'];
        $expected = [];
        foreach ([['clean', 'raid10', 1], ['active', 'raid1', 0], ['readonly', 'raid5', 1],
            ['read-auto', 'raid0', 0], ['active-idle', 'linear', 0],
            ['future-state', 'future-level', 0], ['0', '0', 0]] as $row) {
            $expected[] = $base + ['md' => ['array_state' => $row[0], 'degraded' => $row[2],
                'level' => $row[1]], 'ok' => true];
        }
        $expected[] = $base + ['ok' => false, 'error' => 'unable to read md array status'];
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }
}
