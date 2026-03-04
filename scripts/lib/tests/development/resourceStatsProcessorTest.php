<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/processor.php';

class StubResourceStatsProcessorStatistics extends \resourceStatistics
{
    /** @var array<string, string> */
    public $map = [];

    public function getData($user, $timePeriod = 10080)
    {
        return $this->map[$user] ?? '';
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

    public function tearDown(): void
    {
        $this->cleanupPaths($this->paths);
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

    public function testDetectWorkerUserSanitizesInput(): void
    {
        $processor = $this->makeProcessor(new StubResourceStatsProcessorStatistics());
        $user = $processor->detectWorkerUser(['/scripts/cron/resourceStats.php', 'a!li@ce-1']);
        $this->assertEquals('alice-1', $user);
    }

    public function testProcessUserPersistsMetricsAndDisplays(): void
    {
        $stats = new StubResourceStatsProcessorStatistics();
        $processor = $this->makeProcessor($stats);
        $processor->ensureRuntime();

        $user = 'alice';
        file_put_contents($this->paths['resource_dir'].'/'.$user, 'seed');
        @mkdir($this->paths['home_dir'].'/'.$user, 0755, true);

        $now = time();
        $stats->map[$user] = implode("\n", [
            date('Y-m-d H:i:s', $now - 120).' 1024 2048 10 20 3600 1048576 4',
            date('Y-m-d H:i:s', $now - 3600).' 2048 4096 30 40 7200 2097152 6',
        ]);

        $processor->processUser($user, $processor->buildCompareTimes());

        $saved = $this->readSavedResourceStats($user);
        $this->assertTrue(is_array($saved), 'Expected processed data to be persisted');
        $this->assertTrue(isset($saved['io_read']['raw']['month']));
        $this->assertTrue(isset($saved['ram_hours']['display']['month']));
        $this->assertStringContainsString('KiB', $saved['io_read']['display']['hour']);
        $this->assertStringContainsString('GB-hrs', $saved['ram_hours']['display']['day']);
    }

    public function testProcessUserSkipsInvalidUserWithoutPersisting(): void
    {
        $stats = new StubResourceStatsProcessorStatistics();
        $processor = $this->makeProcessor($stats);

        $stats->map['ghost'] = implode("\n", [
            date('Y-m-d H:i:s', time() - 120).' 1024 2048 1 1 3600 1024 1',
            date('Y-m-d H:i:s', time() - 60).' 2048 4096 1 1 3600 1024 1',
        ]);

        $processor->processUser('ghost', $processor->buildCompareTimes());

        $this->assertTrue($this->readSavedResourceStats('ghost') === null);
    }

    public function testProcessUserSkipsTooLittleData(): void
    {
        $stats = new StubResourceStatsProcessorStatistics();
        $processor = $this->makeProcessor($stats);

        $user = 'alice';
        file_put_contents($this->paths['resource_dir'].'/'.$user, 'seed');
        @mkdir($this->paths['home_dir'].'/'.$user, 0755, true);
        $stats->map[$user] = date('Y-m-d H:i:s', time() - 120).' 1024 2048 1 1 3600 1024 1';

        $processor->processUser($user, $processor->buildCompareTimes());

        $this->assertTrue($this->readSavedResourceStats($user) === null);
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

    /**
     * @return array<string, string>
     */
    private function makePaths(): array
    {
        $root = sys_get_temp_dir().'/pmss-resource-processor-'.bin2hex(random_bytes(4));
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

    /**
     * @param array<string, string> $paths
     */
    private function cleanupPaths(array $paths): void
    {
        if (empty($paths['resource_dir'])) {
            return;
        }

        $root = dirname($paths['resource_dir']);
        if (!is_dir($root)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($root);
    }
}
