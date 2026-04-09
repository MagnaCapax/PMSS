<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/show.php';

class ShowResourcesFormatTest extends TestCase
{
    private function writeResourceStats(string $runtimeDir, string $user, array $payload): void
    {
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/'.$user, $payload);
    }

    private function sampleUsagePayload(array $overrides = []): array
    {
        return $overrides + [
            'io_read' => $this->pmssBuildRawWindowMetric(10), 'io_write' => $this->pmssBuildRawWindowMetric(20), 'io_read_ops' => $this->pmssBuildRawWindowMetric(30, 30, 30, 3600),
            'io_write_ops' => $this->pmssBuildRawWindowMetric(40, 40, 40, 3600), 'cpu' => $this->pmssBuildRawWindowMetric(3600 * 1000000000, 1, 1, 1), 'memory' => ['current' => 1024 * 1024 * 1024, 'raw' => ['month' => 512 * 1024 * 1024]],
            'ram_hours' => $this->pmssBuildRawWindowMetric(2.5), 'tasks' => ['current' => 3],
        ];
    }

    public function testHelpIncludesCoreOptions(): void
    {
        $out = $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--help']);

        $this->assertStringContainsAllStrings(['--json', '--show-missing', '--user', '--help'], $out);
    }

    public function testHelpOutputMatchesSnapshot(): void
    {
        $out = $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--help']);

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
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $this->writeResourceStats($runtimeDir, 'alice', $this->sampleUsagePayload([
            'io_read' => ['raw' => ['month' => 2 * 1024 * 1024 * 1024 * 1024, 'week' => 1, 'day' => 1, 'hour' => 1]],
        ]));
        $out = $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--user=alice'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $this->assertStringContainsString('2.00 TiB', $out);
    }

    public function testUserFilteredOutputFormatsCpuRamAndIops(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $this->writeResourceStats($runtimeDir, 'alice', $this->sampleUsagePayload());
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/showResources.php', ['--user=alice'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $textOutput = $result['output'];
        $this->assertEquals(0, $result['rc']);
        $this->assertStringContainsAllStrings(['1.0 hrs', '2.50 GB-hrs', '1.00 GiB', '2.00'], $textOutput);
    }

    public function testUserFilteredJsonOutputKeepsExpectedPayloadShape(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $this->writeResourceStats($runtimeDir, 'alice', [
            'io_read' => $this->pmssBuildRawWindowMetric(1.0), 'io_write' => $this->pmssBuildRawWindowMetric(2.0), 'io_read_ops' => $this->pmssBuildRawWindowMetric(3.0),
            'io_write_ops' => $this->pmssBuildRawWindowMetric(4.0), 'cpu' => $this->pmssBuildRawWindowMetric(5.0), 'memory' => ['current' => 6.0, 'raw' => ['month' => 7.0]],
            'ram_hours' => $this->pmssBuildRawWindowMetric(8.0), 'tasks' => ['current' => 9.0], 'ignored_field' => ['month' => 999.0],
        ]);
        $json = $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--json', '--user=alice'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $payload = $this->pmssDecodeJsonArray($json);
        $this->assertEquals(6.0, $payload['users']['alice']['memory']['current']);
        $this->assertEquals(8.0, $payload['users']['alice']['ram_hours']['month']);
        $this->assertEquals(9.0, $payload['totals']['tasks']['current']);
        $this->assertEquals([], $payload['missing']);
        $this->assertTrue(!isset($payload['users']['alice']['ignored_field']));
    }

    public function testUserFilteredJsonOutputMatchesSnapshot(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $this->writeResourceStats($runtimeDir, 'alice', [
            'io_read' => $this->pmssBuildRawWindowMetric(1.0), 'io_write' => $this->pmssBuildRawWindowMetric(2.0), 'io_read_ops' => $this->pmssBuildRawWindowMetric(3.0),
            'io_write_ops' => $this->pmssBuildRawWindowMetric(4.0), 'cpu' => $this->pmssBuildRawWindowMetric(5.0), 'memory' => ['current' => 6.0, 'raw' => ['month' => 7.0]],
            'ram_hours' => $this->pmssBuildRawWindowMetric(8.0), 'tasks' => ['current' => 9.0],
        ]);

        $this->assertEquals(
            '{"users":{"alice":{"io_read":{"month":1,"week":1,"day":1,"hour":1},"io_write":{"month":2,"week":2,"day":2,"hour":2},"io_read_ops":{"month":3,"week":3,"day":3,"hour":3},"io_write_ops":{"month":4,"week":4,"day":4,"hour":4},"cpu":{"month":5,"week":5,"day":5,"hour":5},"ram_hours":{"month":8,"week":8,"day":8,"hour":8},"memory":{"current":6,"avg_month":7},"tasks":{"current":9}}},"totals":{"io_read":{"month":1,"week":1,"day":1,"hour":1},"io_write":{"month":2,"week":2,"day":2,"hour":2},"io_read_ops":{"month":3,"week":3,"day":3,"hour":3},"io_write_ops":{"month":4,"week":4,"day":4,"hour":4},"cpu":{"month":5,"week":5,"day":5,"hour":5},"ram_hours":{"month":8,"week":8,"day":8,"hour":8},"memory":{"current":6,"avg_month":7},"tasks":{"current":9}},"missing":[]}'."\n",
            $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--json', '--user=alice'], ['PMSS_RUNTIME_DIR' => $runtimeDir])
        );
    }

    public function testUserFilteredJsonOutputMarksMissingWithoutRows(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        @mkdir($runtimeDir.'/resourceStats', 0755, true);

        $json = $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--json', '--user=ghost'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $payload = $this->pmssDecodeJsonArray($json);
        $this->assertEquals([], $payload['users']);
        $this->assertEquals(0.0, $payload['totals']['memory']['current']);
        $this->assertEquals(['ghost'], $payload['missing']);
    }

    public function testJsonOutputReportsEncodingFailuresOnStderrOnly(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $this->writeResourceStats($runtimeDir, 'alice', $this->sampleUsagePayload([
            'memory' => ['current' => INF, 'raw' => ['month' => 512 * 1024 * 1024]],
        ]));
        $command = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/showResources.php',
            ['--json', '--user=alice'],
            ['PMSS_RUNTIME_DIR' => $runtimeDir],
            'pmss-show-stderr-'
        );
        $this->pmssAssertCommandFailsToStderr($command['result'], $command['stderrPath'], "Failed to encode resource report JSON.\n");
    }

}
