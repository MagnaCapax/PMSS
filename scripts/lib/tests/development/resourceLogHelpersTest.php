<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/log.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class ResourceLogHelpersTest extends TestCase
{
    private function makeRoot(): string
    {
        $root = sys_get_temp_dir().'/pmss-resource-'.bin2hex(random_bytes(4));
        @mkdir($root, 0700, true);
        return $root;
    }

    private function makeCounters(int $ioRead, int $ioWrite, int $cpuNsec, int $memory, int $tasks): array
    {
        return [
            'io_read' => $ioRead,
            'io_write' => $ioWrite,
            'cpu_nsec' => $cpuNsec,
            'memory' => $memory,
            'tasks' => $tasks,
        ];
    }

    private function readState(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function testEnsureDirRejectsRelative(): void
    {
        $this->assertTrue(!\pmssResourceLogEnsureDir('relative/path', 0700));
    }

    public function testEnsureDirCreatesDirectory(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/state';
        $this->assertTrue(\pmssResourceLogEnsureDir($path, 0700));
        $this->assertTrue(is_dir($path));
    }

    public function testEnsureDirRejectsSymlink(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }
        $root = $this->makeRoot();
        $target = $root.'/target';
        @mkdir($target, 0700, true);
        $link = $root.'/link';
        if (!@symlink($target, $link)) {
            throw new SkipTest('symlink creation failed');
        }
        $this->assertTrue(!\pmssResourceLogEnsureDir($link, 0700));
    }

    public function testUserValidationRejectsUppercase(): void
    {
        $this->assertTrue(!\pmssResourceLogIsValidUser('Alice'));
    }

    public function testUpdateStateCreatesStateWhenMissing(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        $counters = $this->makeCounters(10, 20, 30, 4096, 7);

        $result = \pmssResourceLogUpdateState($statePath, $counters);

        $this->assertEquals(10, $result['delta']['io_read']);
        $this->assertEquals(20, $result['delta']['io_write']);
        $this->assertEquals(30, $result['delta']['cpu_nsec']);
        $this->assertEquals(4096, $result['state']['memory']);
        $this->assertEquals(7, $result['state']['tasks']);
        $this->assertTrue($result['state']['ts'] > 0);
        $this->assertTrue(is_file($statePath));
    }

    public function testUpdateStateComputesDeltaFromPrevious(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        file_put_contents($statePath, json_encode([
            'io_read' => 5,
            'io_write' => 2,
            'cpu_nsec' => 100,
            'memory' => 1,
            'tasks' => 1,
            'ts' => 123,
        ]));

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(8, 12, 150, 2048, 3));

        $this->assertEquals(3, $result['delta']['io_read']);
        $this->assertEquals(10, $result['delta']['io_write']);
        $this->assertEquals(50, $result['delta']['cpu_nsec']);
        $state = $this->readState($statePath);
        $this->assertEquals(8, $state['io_read']);
        $this->assertEquals(12, $state['io_write']);
        $this->assertEquals(150, $state['cpu_nsec']);
        $this->assertEquals(2048, $state['memory']);
        $this->assertEquals(3, $state['tasks']);
    }

    public function testUpdateStateHandlesCounterReset(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        file_put_contents($statePath, json_encode([
            'io_read' => 100,
            'io_write' => 200,
            'cpu_nsec' => 300,
            'memory' => 1,
            'tasks' => 1,
            'ts' => 1,
        ]));

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(10, 20, 30, 1024, 2));

        $this->assertEquals(10, $result['delta']['io_read']);
        $this->assertEquals(20, $result['delta']['io_write']);
        $this->assertEquals(30, $result['delta']['cpu_nsec']);
    }

    public function testUpdateStateHandlesInvalidJson(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        file_put_contents($statePath, '{invalid json}');

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(4, 5, 6, 2048, 1));

        $this->assertEquals(4, $result['delta']['io_read']);
        $this->assertEquals(5, $result['delta']['io_write']);
        $this->assertEquals(6, $result['delta']['cpu_nsec']);
    }

    public function testUpdateStateReturnsDeltaWhenFileMissing(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/missing/state.json';

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(9, 8, 7, 512, 1));

        $this->assertEquals(9, $result['delta']['io_read']);
        $this->assertEquals(8, $result['delta']['io_write']);
        $this->assertEquals(7, $result['delta']['cpu_nsec']);
        $this->assertTrue(!is_file($statePath));
    }
}
