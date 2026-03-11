<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/log.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class ResourceLogHelpersTest extends TestCase
{
    private function withFakeSystemctl(array $outputLines, callable $callback): void
    {
        $root = $this->makeRoot();
        $binDir = $root.'/bin';
        @mkdir($binDir, 0755, true);

        $scriptPath = $binDir.'/systemctl';
        $script = "#!/bin/sh\n";
        foreach ($outputLines as $line) {
            $script .= "echo '".str_replace("'", "'\\''", $line)."'\n";
        }
        @file_put_contents($scriptPath, $script);
        @chmod($scriptPath, 0755);

        $originalPath = getenv('PATH');
        $pathPrefix = ($originalPath !== false && $originalPath !== '') ? ':'.$originalPath : '';
        putenv('PATH='.$binDir.$pathPrefix);

        try {
            $callback();
        } finally {
            if ($originalPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH='.$originalPath);
            }
            @unlink($scriptPath);
            @rmdir($binDir);
            @rmdir($root);
        }
    }

    private function makeRoot(): string
    {
        $root = sys_get_temp_dir().'/pmss-resource-'.bin2hex(random_bytes(4));
        @mkdir($root, 0700, true);
        return $root;
    }

    private function makeCounters(int $ioRead, int $ioWrite, int $cpuNsec, int $memory, int $tasks, int $ioReadOps = 0, int $ioWriteOps = 0): array
    {
        return [
            'io_read' => $ioRead,
            'io_write' => $ioWrite,
            'io_read_ops' => $ioReadOps,
            'io_write_ops' => $ioWriteOps,
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

    public function testReadCountersParsesSystemctlOutput(): void
    {
        $this->withFakeSystemctl([
            'IOReadBytes=11',
            'IOWriteBytes=22',
            'IOReadOperations=33',
            'IOWriteOperations=44',
            'CPUUsageNSec=55',
            'MemoryCurrent=66',
            'TasksCurrent=77',
        ], function (): void {
            $counters = \pmssResourceLogReadCounters(1000);
            $this->assertTrue(is_array($counters));
            $this->assertEquals(11, $counters['io_read']);
            $this->assertEquals(22, $counters['io_write']);
            $this->assertEquals(33, $counters['io_read_ops']);
            $this->assertEquals(44, $counters['io_write_ops']);
            $this->assertEquals(55, $counters['cpu_nsec']);
            $this->assertEquals(66, $counters['memory']);
            $this->assertEquals(77, $counters['tasks']);
        });
    }

    public function testReadCountersReturnsNullWhenRequiredFieldMissing(): void
    {
        $this->withFakeSystemctl([
            'IOReadBytes=11',
            'IOWriteBytes=22',
            'CPUUsageNSec=55',
            'MemoryCurrent=66',
        ], function (): void {
            $this->assertTrue(\pmssResourceLogReadCounters(1000) === null);
        });
    }

    public function testReadCountersReturnsNullWhenRequiredValueIsNotNumeric(): void
    {
        $this->withFakeSystemctl([
            'IOReadBytes=11',
            'IOWriteBytes=22',
            'CPUUsageNSec=55',
            'MemoryCurrent=oops',
            'TasksCurrent=77',
        ], function (): void {
            $this->assertTrue(\pmssResourceLogReadCounters(1000) === null);
        });
    }

    public function testUpdateStateCreatesStateWhenMissing(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        $counters = $this->makeCounters(10, 20, 30, 4096, 7);

        $result = \pmssResourceLogUpdateState($statePath, $counters);

        $this->assertEquals(10, $result['delta']['io_read']);
        $this->assertEquals(20, $result['delta']['io_write']);
        $this->assertEquals(0, $result['delta']['io_read_ops']);
        $this->assertEquals(0, $result['delta']['io_write_ops']);
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
            'io_read_ops' => 10,
            'io_write_ops' => 20,
            'cpu_nsec' => 100,
            'memory' => 1,
            'tasks' => 1,
            'ts' => 123,
        ]));

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(8, 12, 150, 2048, 3, 16, 29));

        $this->assertEquals(3, $result['delta']['io_read']);
        $this->assertEquals(10, $result['delta']['io_write']);
        $this->assertEquals(6, $result['delta']['io_read_ops']);
        $this->assertEquals(9, $result['delta']['io_write_ops']);
        $this->assertEquals(50, $result['delta']['cpu_nsec']);
        $state = $this->readState($statePath);
        $this->assertEquals(8, $state['io_read']);
        $this->assertEquals(12, $state['io_write']);
        $this->assertEquals(16, $state['io_read_ops']);
        $this->assertEquals(29, $state['io_write_ops']);
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
