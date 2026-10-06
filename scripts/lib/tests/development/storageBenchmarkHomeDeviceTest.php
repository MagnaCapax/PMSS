<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageBenchmark.php';

class StorageBenchmarkHomeDeviceTest extends TestCase
{
    public function testOptionAndUsage(): void
    {
        $parsed = \pmssParseCliTokens(['storageBenchmark.php', '--home-device'], []);
        $this->assertTrue(\pmssCliOptionPresent($parsed, 'home-device', null, true));
        $help = $this->pmssRunRepoPhpScript('scripts/util/storageBenchmark.php', ['--help']);
        $this->assertStringContainsString('--home-device', $help);
        $this->assertStringContainsString('Read-only test of the block device', $help);
    }

    public function testPlainDiskAndMdMetadata(): void
    {
        $sysfs = $this->pmssMakeTempDir('pmss-home-sysfs-');
        mkdir($sysfs.'/sda/queue', 0755, true);
        file_put_contents($sysfs.'/sda/queue/rotational', "1\n");
        mkdir($sysfs.'/md0/queue', 0755, true);
        mkdir($sysfs.'/md0/md', 0755, true);
        file_put_contents($sysfs.'/md0/queue/rotational', "0\n");
        file_put_contents($sysfs.'/md0/md/array_state', "clean\n");
        file_put_contents($sysfs.'/md0/md/degraded', "1\n");
        $check = static function (): bool { return true; };
        $canonical = static function (string $path): string { return $path; };
        $size = static function (): int { return 1024 * 1024 * 1024; };

        $disk = \storageBenchmarkHomeDeviceResolve('/home', '/dev/sda', $sysfs, $check, $canonical, $size);
        $this->assertSame(true, $disk['ok']);
        $this->assertSame(1, $disk['rota']);
        $this->assertSame(1024 * 1024 * 1024, $disk['size']);
        $this->assertFalse(isset($disk['md']));

        $md = \storageBenchmarkHomeDeviceResolve('/home', '/dev/md0', $sysfs, $check, $canonical, $size);
        $this->assertSame(true, $md['ok']);
        $this->assertSame(0, $md['rota']);
        $this->assertSame(['array_state' => 'clean', 'degraded' => 1], $md['md']);
    }

    public function testUnsafeAndNonBlockMountsAreRejected(): void
    {
        $sysfs = $this->pmssMakeTempDir('pmss-home-sysfs-');
        $fixture = $sysfs.'/regular';
        file_put_contents($fixture, 'fixture');
        $check = static function () use ($fixture): bool { return is_readable($fixture) && filetype($fixture) === 'block'; };
        $canonical = static function (string $path): string { return $path; };
        $size = static function (): int { return 1024 * 1024; };
        foreach (['overlay', '/dev/../etc/passwd', "/dev/sda\nother", '/dev/sda'.chr(0).'x'] as $unsafe) {
            $result = \storageBenchmarkHomeDeviceResolve('/home', $unsafe, $sysfs, $check, $canonical, $size);
            $this->assertSame(false, $result['ok']);
            $this->assertSame('unsafe or unavailable mount device', $result['error']);
        }
        $result = \storageBenchmarkHomeDeviceResolve('/home', '/dev/sda', $sysfs, $check, $canonical, $size);
        $this->assertSame(false, $result['ok']);
        $this->assertSame('mount device is not a readable block device', $result['error']);
    }

    public function testMissingFioRecordsUnmeasuredRandomAndRunsDdOnly(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-home-log-');
        $log = $dir.'/benchmark.jsonl';
        $calls = [];
        $dd = static function (string $path, int $count, int $skip) use (&$calls): array {
            $calls[] = [$path, $count, $skip];
            return ['rc' => 0, 'mbps' => 123.45, 'secs' => 1.5];
        };
        ob_start();
        \storageBenchmarkHomeDeviceRunTests($log, \storageBenchmarkEntryBase('now', '', 'run'),
            ['device' => '/dev/md0', 'rota' => 0, 'size' => 1024 * 1024 * 1024,
                'mode' => 'home-device', 'md' => ['array_state' => 'clean', 'degraded' => 0]],
            2 * 1024 * 1024, 30, false, null, $dd);
        $output = ob_get_clean();
        $rows = array_map('json_decode', file($log, FILE_IGNORE_NEW_LINES));
        $this->assertSame('home-device-randread-4k', $rows[0]->test);
        $this->assertSame(false, $rows[0]->measured);
        $this->assertSame('home-device-seqread-dd', $rows[1]->test);
        $this->assertSame(123.45, $rows[1]->metrics->seqread_MBps);
        $this->assertSame(2, $calls[0][1]);
        $this->assertTrue($calls[0][2] >= 0);
        $this->assertStringContainsString('not measured', $output);
    }

    public function testFioJobsStayReadOnlyAndUseRequestedRuntime(): void
    {
        $log = $this->pmssMakeTempDir('pmss-home-log-').'/benchmark.jsonl';
        $jobs = [];
        $fio = static function (string $path, int $size, int $runtime, array $job) use (&$jobs): array {
            $jobs[] = [$path, $size, $runtime, $job];
            return ['ok' => true, 'result' => ['read_bw_MBps' => 10.0,
                'read_iops' => 20.0, 'read_p95_ms' => 1.0]];
        };
        ob_start();
        \storageBenchmarkHomeDeviceRunTests($log, \storageBenchmarkEntryBase('now', '', 'run'),
            ['device' => '/dev/md0', 'rota' => null, 'size' => 1024 * 1024,
                'mode' => 'home-device'], 0, 17, true, $fio);
        ob_end_clean();
        $rows = array_map('json_decode', file($log, FILE_IGNORE_NEW_LINES));
        $this->assertSame(2, count($jobs));
        $this->assertSame(['home-device-randread-4k', 'home-device-seqread-1M'],
            array_column($rows, 'test'));
        $this->assertSame(['randread', 'read'], array_column(array_column($jobs, 3), 'rw'));
        $this->assertSame(['4k', '1M'], array_column(array_column($jobs, 3), 'bs'));
        $this->assertSame([64, 32], array_column(array_column($jobs, 3), 'iodepth'));
        foreach ($jobs as $job) {
            $this->assertSame('/dev/md0', $job[0]);
            $this->assertSame(17, $job[2]);
            $this->assertSame(1, $job[3]['numjobs']);
            $this->assertSame(1, $job[3]['direct']);
            $this->assertSame(true, $job[3]['readonly']);
        }
        $this->assertSame(10, $rows[0]->metrics->read_bw_MBps);
    }

    public function testFioReadonlyIsOptIn(): void
    {
        $script = <<<'PHP'
namespace HomeFioFixture;
function pmssCreatePrivateTempFile($prefix) { return getenv('PMSS_TEST_FIO_PATH'); }
function runCommand($command, $verbose) { $GLOBALS['command'] = $command; return 0; }
function file_get_contents($path) { return '{"jobs":[{"read":{"bw_bytes":1048576,"iops":10}}]}'; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/storageBenchmark.php', 'HomeFioFixture');
        $script .= <<<'PHP'
$job = ['name' => 'test', 'rw' => 'read', 'bs' => '1M', 'iodepth' => 1, 'numjobs' => 1, 'direct' => 1];
fioRun('/dev/example', 1048576, 1, $job);
$ordinary = $GLOBALS['command'];
$job['readonly'] = true;
fioRun('/dev/example', 1048576, 1, $job);
echo json_encode([$ordinary, $GLOBALS['command']]);
PHP;
        $commands = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_FIO_PATH' => $this->pmssMakeTempPath('pmss-home-fio-')]);
        $this->assertStringNotContainsString('--readonly', $commands[0]);
        $this->assertSame($commands[0].' --readonly', $commands[1]);
    }
}
