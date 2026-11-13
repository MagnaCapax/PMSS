<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StorageBenchmarkHomeDeviceInputSafetyTest extends TestCase
{
    /** Isolate every observation: no command, sysfs read or real device probe runs. */
    private function fixtureScript(): string
    {
        $script = "namespace BenchmarkHomeDeviceInputFixture;\nrequire_once ".
            var_export($this->pmssRepoPath('scripts/lib/storageBenchmark.php'), true).";\n";
        $script .= <<<'PHP'
function storageBenchmarkCommandFieldRead($command) {
    $GLOBALS['events'][] = ['command', $command];
    return '/dev/fixture';
}
function file_get_contents($path) { $GLOBALS['events'][] = ['read', $path]; return "1\n"; }
function is_dir($path) { $GLOBALS['events'][] = ['directory', $path]; return false; }
$deviceCheck = static function ($path) { $GLOBALS['events'][] = ['device', $path]; return true; };
$canonicalPath = static function ($path) { $GLOBALS['events'][] = ['canonical', $path]; return $path; };
$sizeRead = static function ($path) { $GLOBALS['events'][] = ['size', $path]; return 4096; };
PHP;
        return $script.$this->pmssInlinePhpLibraryInNamespace(
            'scripts/lib/storageBenchmark/homeDeviceInspection.php', 'BenchmarkHomeDeviceInputFixture');
    }

    public function testNulRootsAreRefusedBeforeAnyObservation(): void
    {
        $script = $this->fixtureScript().<<<'PHP'
$cases = [];
foreach ([null, '/dev/fixture'] as $mountDevice) {
    foreach (["\0", "\0/fixture/sys", "/fixture/sys\0", "/fixture/\0sys",
        "/fixture/sys\0suffix", " \0/fixture/sys\0\n"] as $root) {
        $GLOBALS['events'] = [];
        $cases[] = [storageBenchmarkHomeDeviceResolve('/home', $mountDevice, $root,
            $deviceCheck, $canonicalPath, $sizeRead), $GLOBALS['events']];
    }
}
echo json_encode($cases);
PHP;
        $expected = [];
        foreach ([null, '/dev/fixture'] as $mountDevice) {
            $result = ['device' => $mountDevice, 'rota' => null, 'size' => null,
                'mode' => 'home-device', 'ok' => false, 'error' => 'unsafe or unavailable mount device'];
            $expected = array_merge($expected, array_fill(0, 6, [$result, []]));
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }

    public function testNulTargetsAreRefusedBeforeShellQuoting(): void
    {
        $script = $this->fixtureScript().<<<'PHP'
$cases = [];
foreach (["\0", "\0/home", "/home\0", "/ho\0me", "/home\0suffix", " \0/home\0\n"] as $target) {
    $GLOBALS['events'] = [];
    $cases[] = [storageBenchmarkHomeDeviceResolve($target, null, '/fixture/sys',
        $deviceCheck, $canonicalPath, $sizeRead), $GLOBALS['events']];
}
echo json_encode($cases);
PHP;
        $result = ['device' => null, 'rota' => null, 'size' => null,
            'mode' => 'home-device', 'ok' => false, 'error' => 'unsafe or unavailable mount device'];
        $this->assertSame(array_fill(0, 6, [$result, []]), $this->pmssRunInlinePhpJson($script));
    }

    public function testValidPathsKeepProbeOrderAndExplicitDeviceIgnoresTarget(): void
    {
        $script = $this->fixtureScript().<<<'PHP'
$cases = [];
foreach ([["/fixture/home's data", null, '/fixture/sys///'],
    ["/unused\0target", '/dev/fixture', '/fixture/sys'],
    ['', '/dev/fixture', ''], ['/home', '/dev/fixture', 'relative/sys']] as $args) {
    $GLOBALS['events'] = [];
    $cases[] = [storageBenchmarkHomeDeviceResolve($args[0], $args[1], $args[2],
        $deviceCheck, $canonicalPath, $sizeRead), $GLOBALS['events']];
}
echo json_encode($cases);
PHP;
        $result = ['device' => '/dev/fixture', 'rota' => 1, 'size' => 4096,
            'mode' => 'home-device', 'ok' => true];
        $expected = [];
        foreach (['/fixture/sys', '/fixture/sys', '', 'relative/sys'] as $index => $root) {
            $events = [['device', '/dev/fixture'], ['canonical', '/dev/fixture'], ['size', '/dev/fixture'],
                ['read', $root.'/fixture/queue/rotational'], ['directory', $root.'/fixture/md']];
            if ($index === 0) {
                array_unshift($events, ['command', "df -P '/fixture/home'\\''s data' | awk 'NR==2 {print $1}'"]);
            }
            $expected[] = [$result, $events];
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }
}
