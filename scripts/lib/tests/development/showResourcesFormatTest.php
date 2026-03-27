<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/show.php';

class ShowResourcesFormatTest extends TestCase
{
    private function scriptPath(): string
    {
        return dirname(__DIR__, 3).'/showResources.php';
    }

    private function runScript(array $arguments, array $environment = []): string
    {
        return $this->pmssRunPhpScript($this->scriptPath(), $arguments, $environment);
    }

    private function makeRuntimeDir(): string
    {
        return $this->pmssMakeTempDir('pmss-show-runtime-');
    }

    private function writeResourceStats(string $runtimeDir, string $user, array $payload): void
    {
        $statsDir = $runtimeDir.'/resourceStats';
        @mkdir($statsDir, 0755, true);
        @file_put_contents($statsDir.'/'.$user, serialize($payload));
    }

    private function sampleUsagePayload(array $overrides = []): array
    {
        return $overrides + [
            'io_read' => ['raw' => ['month' => 10, 'week' => 10, 'day' => 10, 'hour' => 10]],
            'io_write' => ['raw' => ['month' => 20, 'week' => 20, 'day' => 20, 'hour' => 20]],
            'io_read_ops' => ['raw' => ['month' => 30, 'week' => 30, 'day' => 30, 'hour' => 3600]],
            'io_write_ops' => ['raw' => ['month' => 40, 'week' => 40, 'day' => 40, 'hour' => 3600]],
            'cpu' => ['raw' => ['month' => 3600 * 1000000000, 'week' => 1, 'day' => 1, 'hour' => 1]],
            'memory' => ['current' => 1024 * 1024 * 1024, 'raw' => ['month' => 512 * 1024 * 1024]],
            'ram_hours' => ['raw' => ['month' => 2.5, 'week' => 2.5, 'day' => 2.5, 'hour' => 2.5]],
            'tasks' => ['current' => 3],
        ];
    }

    public function testHelpIncludesCoreOptions(): void
    {
        $out = $this->runScript(['--help']);

        $this->assertTrue(strpos($out, '--json') !== false);
        $this->assertTrue(strpos($out, '--show-missing') !== false);
        $this->assertTrue(strpos($out, '--user') !== false);
        $this->assertTrue(strpos($out, '--help') !== false);
    }

    public function testHelpOutputMatchesSnapshot(): void
    {
        $out = $this->runScript(['--help']);

        $this->assertEquals(
            "Usage: showResources.php [--json] [--show-missing] [--user=<username>]\n\n"
            ."Options:\n"
            ."  --json          Emit JSON instead of human text output.\n"
            ."  --show-missing  Print missing stats usernames (text mode only).\n"
            ."  --user          Show only the named user.\n"
            ."  --help          Show this help.\n\n",
            $out
        );
    }

    public function testFormatBytesTiB(): void
    {
        $runtimeDir = $this->makeRuntimeDir();
        $this->writeResourceStats($runtimeDir, 'alice', $this->sampleUsagePayload([
            'io_read' => ['raw' => ['month' => 2 * 1024 * 1024 * 1024 * 1024, 'week' => 1, 'day' => 1, 'hour' => 1]],
        ]));
        $out = $this->runScript(['--user=alice'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $this->assertTrue(strpos($out, '2.00 TiB') !== false);
    }

    public function testUserFilteredOutputFormatsCpuRamAndIops(): void
    {
        $runtimeDir = $this->makeRuntimeDir();
        $this->writeResourceStats($runtimeDir, 'alice', $this->sampleUsagePayload());
        $result = $this->pmssExecShellCommand(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->scriptPath()).' --user=alice',
            ['PMSS_RUNTIME_DIR' => $runtimeDir]
        );

        $textOutput = $result['output'];
        $this->assertEquals(0, $result['rc']);
        $this->assertTrue(strpos($textOutput, '1.0 hrs') !== false);
        $this->assertTrue(strpos($textOutput, '2.50 GB-hrs') !== false);
        $this->assertTrue(strpos($textOutput, '1.00 GiB') !== false);
        $this->assertTrue(strpos($textOutput, '2.00') !== false);
    }

    public function testUserFilteredJsonOutputKeepsExpectedPayloadShape(): void
    {
        $runtimeDir = $this->makeRuntimeDir();
        $this->writeResourceStats($runtimeDir, 'alice', [
            'io_read' => ['raw' => ['month' => 1.0, 'week' => 1.0, 'day' => 1.0, 'hour' => 1.0]],
            'io_write' => ['raw' => ['month' => 2.0, 'week' => 2.0, 'day' => 2.0, 'hour' => 2.0]],
            'io_read_ops' => ['raw' => ['month' => 3.0, 'week' => 3.0, 'day' => 3.0, 'hour' => 3.0]],
            'io_write_ops' => ['raw' => ['month' => 4.0, 'week' => 4.0, 'day' => 4.0, 'hour' => 4.0]],
            'cpu' => ['raw' => ['month' => 5.0, 'week' => 5.0, 'day' => 5.0, 'hour' => 5.0]],
            'memory' => ['current' => 6.0, 'raw' => ['month' => 7.0]],
            'ram_hours' => ['raw' => ['month' => 8.0, 'week' => 8.0, 'day' => 8.0, 'hour' => 8.0]],
            'tasks' => ['current' => 9.0],
            'ignored_field' => ['month' => 999.0],
        ]);
        $json = $this->runScript(['--json', '--user=alice'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $payload = json_decode($json, true);
        $this->assertTrue(is_array($payload));
        $this->assertEquals(6.0, $payload['users']['alice']['memory']['current']);
        $this->assertEquals(8.0, $payload['users']['alice']['ram_hours']['month']);
        $this->assertEquals(9.0, $payload['totals']['tasks']['current']);
        $this->assertEquals([], $payload['missing']);
        $this->assertTrue(!isset($payload['users']['alice']['ignored_field']));
    }

    public function testUserFilteredJsonOutputMarksMissingWithoutRows(): void
    {
        $runtimeDir = $this->makeRuntimeDir();
        @mkdir($runtimeDir.'/resourceStats', 0755, true);

        $json = $this->runScript(['--json', '--user=ghost'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $payload = json_decode($json, true);
        $this->assertTrue(is_array($payload));
        $this->assertEquals([], $payload['users']);
        $this->assertEquals(0.0, $payload['totals']['memory']['current']);
        $this->assertEquals(['ghost'], $payload['missing']);
    }

}
