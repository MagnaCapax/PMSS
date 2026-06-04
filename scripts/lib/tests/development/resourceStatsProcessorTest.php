<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/processor.php';
require_once dirname(__DIR__, 2).'/pmssStats.php';

class StubResourceStatsProcessorStatistics extends \resourceStatistics
{
    /** @var array<string, string> */
    public $map = [];

    public function getData($user, $timePeriod = 10080)
    {
        return $this->map[$user] ?? '';
    }
}

class SpyResourceStatsProcessor extends \ResourceStatsProcessor
{
    /** @var array<int, string> */
    public $usersToDiscover = [];
    /** @var array<int, array<int, mixed>> */
    public $spawnCalls = [];

    public function discoverUsers(): array
    {
        return $this->usersToDiscover;
    }

    public function spawnWorkers(string $scriptPath, array $users): void
    {
        $this->spawnCalls[] = [$scriptPath, $users];
    }
}

class ResourceStatsProcessorTest extends TestCase
{
    /** @var array<string, string> */
    private $paths = [];

    public function setUp(): void
    {
        $this->paths = $this->makePaths();
    }

    public function testDiscoverUsersReturnsNaturalSortedFiles(): void
    {
        file_put_contents($this->paths['resource_dir'].'/user2', 'seed');
        file_put_contents($this->paths['resource_dir'].'/user10', 'seed');
        file_put_contents($this->paths['resource_dir'].'/user1', 'seed');

        $processor = $this->makeProcessor(new StubResourceStatsProcessorStatistics());
        $users = $processor->discoverUsers();

        $this->assertEquals(['user1', 'user2', 'user10'], $users);
    }

    public function testValidateUserRequiresResourcePasswdAndHome(): void
    {
        $user = 'alice';
        $processor = $this->makeProcessor(new StubResourceStatsProcessorStatistics());

        $this->assertTrue(!$processor->validateUser($user));

        file_put_contents($this->paths['resource_dir'].'/'.$user, 'seed');
        $this->assertTrue(!$processor->validateUser($user));

        @mkdir($this->paths['home_dir'].'/'.$user, 0755, true);
        $this->assertTrue($processor->validateUser($user));
    }

    public function testEnsureRuntimeCreatesRuntimeDirectories(): void
    {
        $processor = $this->makeProcessor(new StubResourceStatsProcessorStatistics());

        $this->assertTrue(!is_dir($this->paths['runtime_dir'].'/resourceStats'));

        $processor->ensureRuntime();

        $this->assertTrue(is_dir($this->paths['runtime_dir']));
        $this->assertTrue(is_dir($this->paths['runtime_dir'].'/resourceStats'));
    }

    public function testEnsureRuntimeRejectsSymlinkedRuntimeDir(): void
    {
        $targetRuntime = dirname($this->paths['runtime_dir']).'/runtime-target';
        @mkdir($targetRuntime, 0755, true);
        @rmdir($this->paths['runtime_dir']);
        $this->pmssCreateSymlinkOrSkip($targetRuntime, $this->paths['runtime_dir']);

        $processor = $this->makeProcessor(new StubResourceStatsProcessorStatistics());
        $processor->ensureRuntime();

        $this->assertTrue(is_link($this->paths['runtime_dir']));
        $this->assertTrue(!is_dir($targetRuntime.'/resourceStats'));
    }

    public function testEnsureRuntimeLogsWarningWhenRuntimeDirIsUnsafe(): void
    {
        $messages = [];
        $targetRuntime = dirname($this->paths['runtime_dir']).'/runtime-target';
        @mkdir($targetRuntime, 0755, true);
        @rmdir($this->paths['runtime_dir']);
        $this->pmssCreateSymlinkOrSkip($targetRuntime, $this->paths['runtime_dir']);

        $processor = new \ResourceStatsProcessor(new StubResourceStatsProcessorStatistics(), $this->paths + [
            'logger' => $this->pmssMakeArrayLogger($messages),
        ]);
        $processor->ensureRuntime();

        $this->assertTrue($this->pmssMessagesContain($messages, 'Unable to prepare resource runtime directory'));
        $this->assertTrue(!is_dir($targetRuntime.'/resourceStats'));
    }

    public function testProcessUserPersistsMetricsWithoutDisplayCache(): void
    {
        $stats = new StubResourceStatsProcessorStatistics();
        $processor = $this->makeProcessor($stats);

        $user = $this->seedManagedUser();

        $now = time();
        $this->setResourceData($stats, $user, [
            $this->resourceLine($now - 120, '1024 2048 10 20 3600 1048576 4'),
            $this->resourceLine($now - 3600, '2048 4096 30 40 7200 2097152 6'),
        ]);

        $processor->processUser($user, \pmssStatsCompareTimesBuild());

        $saved = $this->readSavedResourceStats($user);
        $this->assertTrue(is_array($saved), 'Expected processed data to be persisted');
        $this->assertTrue(is_dir($this->paths['runtime_dir'].'/resourceStats'));
        $this->assertTrue(isset($saved['io_read']['raw']['month']));
        $this->assertTrue(!isset($saved['io_read']['display']));
        $this->assertTrue(!isset($saved['memory']['display']));
        $this->assertTrue(!isset($saved['ram_hours']['display']));
        $this->assertTrue(!isset($saved['tasks']['display']));
        $this->assertEquals(6.0, $saved['tasks']['current']);
    }

    public function testProcessUserLogsWriteFailureAndSkipsSuccessLogWhenRuntimeWriteFails(): void
    {
        $messages = [];
        $stats = new StubResourceStatsProcessorStatistics();
        $paths = $this->paths;
        $targetRuntime = dirname($paths['runtime_dir']).'/runtime-target';
        @mkdir($targetRuntime, 0755, true);
        @rmdir($paths['runtime_dir']);
        $this->pmssCreateSymlinkOrSkip($targetRuntime, $paths['runtime_dir']);

        $processor = new \ResourceStatsProcessor($stats, $paths + [
            'logger' => $this->pmssMakeArrayLogger($messages),
        ]);

        $user = $this->seedManagedUser();

        $now = time();
        $this->setResourceData($stats, $user, [
            $this->resourceLine($now - 120, '1024 2048 10 20 3600 1048576 4'),
            $this->resourceLine($now - 60, '2048 4096 30 40 7200 2097152 6'),
        ]);

        $processor->processUser($user, \pmssStatsCompareTimesBuild());

        $this->assertTrue($this->pmssMessagesContain($messages, 'Unable to prepare resource runtime directory'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Failed to write resource stats for '.$user.' at '.$paths['runtime_dir'].'/resourceStats/'.$user));
        $this->assertFalse($this->pmssMessagesContain($messages, 'Resource stats for '.$user.' saved, month read bytes:'));
        $this->assertTrue(is_file($paths['home_dir'].'/'.$user.'/.resourceData'));
        $this->assertTrue(!is_file($targetRuntime.'/resourceStats/'.$user));
    }

    public function testProcessUserSavedPayloadStillFeedsPmssStats(): void
    {
        $stats = new StubResourceStatsProcessorStatistics();
        $processor = $this->makeProcessor($stats);

        $user = $this->seedManagedUser();

        $now = time();
        $this->setResourceData($stats, $user, [
            $this->resourceLine($now - 120, '1048576 2097152 3600 7200 3600000000000 1073741824 4'),
            $this->resourceLine($now - 60, '2097152 3145728 7200 10800 7200000000000 2147483648 6'),
        ]);

        $processor->processUser($user, \pmssStatsCompareTimesBuild());

        $configDir = $this->pmssMakeTempDir('pmss-stats-config-');
        $cgroupDir = $this->pmssMakeTempDir('pmss-stats-cgroup-');
        $this->pmssWriteRelativeFile($configDir, 'users/alice.json', json_encode([
            'ramMiB' => 4096,
            'product' => 'M10G S',
        ]));
        foreach ([
            'memory.current' => "2147483648\n",
            'memory.max' => "4294967296\n",
            'pids.current' => "12\n",
            'cpu.stat' => "usage_usec 42000000\n",
            'io.stat' => "8:0 rbytes=1024 wbytes=2048 rios=1 wios=2 dbytes=0 dios=0\n",
        ] as $relativePath => $content) {
            $this->pmssWriteRelativeFile($cgroupDir, $relativePath, $content);
        }
        $this->pmssWriteFile(dirname($cgroupDir).'/io.pressure', "some avg10=1.5 avg60=0.5 avg300=0.1 total=10\n");

        $payload = \pmssStatsCollect([
            'user' => 'alice',
            'home' => $this->paths['home_dir'].'/alice',
            'config_dir' => $configDir,
            'cgroup_dir' => $cgroupDir,
            'version_file' => $this->pmssWriteTempFile('stats-version', "3.0.0\n"),
        ], static function (): bool {
            return false;
        });

        $this->assertEquals('alice', $payload['context']['user']);
        $this->assertEquals(2147483648.0, $payload['memory']['current_bytes']);
        $this->assertEquals('M10G S', $payload['product']);
    }

    public function testProcessUserPersistsMemoryBreakdownCurrentValues(): void
    {
        $stats = new StubResourceStatsProcessorStatistics();
        $processor = $this->makeProcessor($stats);

        $user = $this->seedManagedUser();

        $now = time();
        $this->setResourceData($stats, $user, [
            $this->resourceLine($now - 120, '1024 2048 10 20 3600 1048576 4 524288 262144'),
            $this->resourceLine($now - 60, '2048 4096 30 40 7200 2097152 6 1048576 524288'),
        ]);

        $processor->processUser($user, \pmssStatsCompareTimesBuild());

        $saved = $this->readSavedResourceStats($user);
        $this->assertEquals(1048576.0, $saved['memory']['anon']);
        $this->assertEquals(524288.0, $saved['memory']['file']);
    }

    public function testProcessUserSkipsInvalidUserWithoutPersisting(): void
    {
        $stats = new StubResourceStatsProcessorStatistics();
        $processor = $this->makeProcessor($stats);

        $this->setResourceData($stats, 'ghost', [
            $this->resourceLine(time() - 120, '1024 2048 1 1 3600 1024 1'),
            $this->resourceLine(time() - 60, '2048 4096 1 1 3600 1024 1'),
        ]);

        $processor->processUser('ghost', \pmssStatsCompareTimesBuild());

        $this->assertTrue($this->readSavedResourceStats('ghost') === null);
    }

    public function testProcessUserSkipsTooLittleData(): void
    {
        $stats = new StubResourceStatsProcessorStatistics();
        $processor = $this->makeProcessor($stats);

        $user = $this->seedManagedUser();
        $stats->map[$user] = $this->resourceLine(time() - 120, '1024 2048 1 1 3600 1024 1');

        $processor->processUser($user, \pmssStatsCompareTimesBuild());

        $this->assertTrue($this->readSavedResourceStats($user) === null);
    }

    public function testRunCliProcessesWorkerUser(): void
    {
        $stats = new StubResourceStatsProcessorStatistics();
        $processor = new SpyResourceStatsProcessor($stats, $this->paths);
        $user = $this->seedManagedUser();

        $now = time();
        $this->setResourceData($stats, $user, [
            $this->resourceLine($now - 120, '1024 2048 10 20 3600 1048576 4'),
            $this->resourceLine($now - 60, '2048 4096 30 40 7200 2097152 6'),
        ]);

        $this->assertEquals(0, $processor->runCli(['/scripts/cron/resourceStats.php', 'a!li@ce'], '/scripts/cron/resourceStats.php'));
        $this->assertTrue($this->readSavedResourceStats($user) !== null);
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliReportsInvalidWorkerUser(): void
    {
        $processor = new SpyResourceStatsProcessor(new StubResourceStatsProcessorStatistics(), $this->paths);

        list($result, $output) = $this->pmssCaptureStdout(function () use ($processor): int { return $processor->runCli(['/scripts/cron/resourceStats.php', 'ghost'], '/scripts/cron/resourceStats.php'); });

        $this->assertEquals(0, $result);
        $this->assertStringContainsString("Invalid user specified: ghost\n", $output);
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliPrintsNoUsersMessageWithoutDiscoveredUsers(): void
    {
        $processor = new SpyResourceStatsProcessor(new StubResourceStatsProcessorStatistics(), $this->paths);

        list($result, $output) = $this->pmssCaptureStdout(function () use ($processor): int { return $processor->runCli(['/scripts/cron/resourceStats.php'], '/scripts/cron/resourceStats.php'); });

        $this->assertEquals(0, $result);
        $this->assertStringContainsString("No users in this system!\n", $output);
        $this->assertEquals([], $processor->spawnCalls);
    }

    public function testRunCliSpawnsWorkersForDiscoveredUsers(): void
    {
        $processor = new SpyResourceStatsProcessor(new StubResourceStatsProcessorStatistics(), $this->paths);
        $processor->usersToDiscover = ['alice', 'bob'];

        $this->assertEquals(0, $processor->runCli(['/scripts/cron/resourceStats.php'], '/scripts/cron/resourceStats.php'));
        $this->assertEquals([['/scripts/cron/resourceStats.php', ['alice', 'bob']]], $processor->spawnCalls);
    }

    public function testBeforeSpawnSkipsWhenResourceStatsLockIsBusy(): void
    {
        $lockPath = $this->paths['runtime_dir'].'/resourceStats.lock';
        $busy = false;
        $handle = \pmssLockFileAcquire($lockPath, true, 'c+', false, true, $busy);
        $this->assertTrue(is_resource($handle), 'Expected test to acquire resource stats lock');

        try {
            $processor = $this->makeProcessor(new StubResourceStatsProcessorStatistics());
            $this->assertFalse($processor->beforeSpawn());
        } finally {
            if (is_resource($handle)) {
                \pmssLockHandleRelease($handle);
            }
        }
    }

    public function testBeforeSpawnLogsUnsafeResourceStatsLockPathAndContinues(): void
    {
        $messages = [];
        $target = $this->pmssWriteFile($this->paths['runtime_dir'].'/lock-target', '');
        $this->pmssCreateSymlinkOrSkip($target, $this->paths['runtime_dir'].'/resourceStats.lock');

        $processor = new \ResourceStatsProcessor(new StubResourceStatsProcessorStatistics(), $this->paths + [
            'logger' => $this->pmssMakeArrayLogger($messages),
        ]);

        $this->assertTrue($processor->beforeSpawn());
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unable to open lock file '.$this->paths['runtime_dir'].'/resourceStats.lock'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSavedResourceStats(string $user): ?array
    {
        $path = $this->paths['home_dir'].'/'.$user.'/.resourceData';
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = @unserialize($raw);
        return is_array($decoded) ? $decoded : null;
    }

    private function makeProcessor(StubResourceStatsProcessorStatistics $stats): \ResourceStatsProcessor
    {
        return new \ResourceStatsProcessor($stats, $this->paths);
    }

    private function seedManagedUser(string $user = 'alice'): string
    {
        file_put_contents($this->paths['resource_dir'].'/'.$user, 'seed');
        @mkdir($this->paths['home_dir'].'/'.$user, 0755, true);
        return $user;
    }

    private function setResourceData(StubResourceStatsProcessorStatistics $stats, string $user, array $lines): void
    {
        $stats->map[$user] = implode("\n", $lines);
    }

    private function resourceLine(int $timestamp, string $payload): string
    {
        return date('Y-m-d H:i:s', $timestamp).' '.$payload;
    }

    /**
     * @return array<string, string>
     */
    private function makePaths(): array
    {
        $root = $this->pmssMakeTempDir('pmss-resource-processor-');
        $paths = [
            'resource_dir' => $root.'/resources',
            'home_dir' => $root.'/home',
            'runtime_dir' => $root.'/run',
            'passwd_file' => $root.'/passwd',
        ];

        @mkdir($paths['resource_dir'], 0755, true);
        @mkdir($paths['home_dir'], 0755, true);
        @mkdir($paths['runtime_dir'], 0755, true);
        file_put_contents($paths['passwd_file'], "alice:x:1000:1000::{$paths['home_dir']}/alice:/bin/bash\n");

        return $paths;
    }
}
