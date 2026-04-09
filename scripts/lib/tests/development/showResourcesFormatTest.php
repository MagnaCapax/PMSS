<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/show.php';

class ShowResourcesFormatTest extends TestCase
{
    private function sampleUsageValues(array $overrides = []): array
    {
        return $overrides + [
            'io_read' => $this->pmssBuildWindowValues(10),
            'io_write' => $this->pmssBuildWindowValues(20),
            'io_read_ops' => $this->pmssBuildWindowValues(30, 30, 30, 3600),
            'io_write_ops' => $this->pmssBuildWindowValues(40, 40, 40, 3600),
            'cpu' => $this->pmssBuildWindowValues(3600 * 1000000000, 1, 1, 1),
            'memory' => $this->pmssBuildWindowValues(512 * 1024 * 1024),
            'memory_current' => 1024 * 1024 * 1024,
            'memory_avg_month' => 512 * 1024 * 1024,
            'ram_hours' => $this->pmssBuildWindowValues(2.5),
            'tasks' => $this->pmssBuildWindowValues(3),
            'tasks_current' => 3,
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
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/alice', $this->pmssBuildResourceStatsPayloadFromValues($this->sampleUsageValues([
            'io_read' => $this->pmssBuildWindowValues(2 * 1024 * 1024 * 1024 * 1024, 1, 1, 1),
        ])));
        $out = $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--user=alice'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $this->assertStringContainsString('2.00 TiB', $out);
    }

    public function testUserFilteredOutputFormatsCpuRamAndIops(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/alice', $this->pmssBuildResourceStatsPayloadFromValues($this->sampleUsageValues()));
        $result = $this->pmssRunRepoPhpScriptCommand('scripts/showResources.php', ['--user=alice'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $textOutput = $result['output'];
        $this->assertEquals(0, $result['rc']);
        $this->assertStringContainsAllStrings(['1.0 hrs', '2.50 GB-hrs', '1.00 GiB', '2.00'], $textOutput);
    }

    public function testUserFilteredJsonOutputKeepsExpectedPayloadShape(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $values = [
            'io_read' => $this->pmssBuildWindowValues(1.0), 'io_write' => $this->pmssBuildWindowValues(2.0), 'io_read_ops' => $this->pmssBuildWindowValues(3.0),
            'io_write_ops' => $this->pmssBuildWindowValues(4.0), 'cpu' => $this->pmssBuildWindowValues(5.0), 'memory' => $this->pmssBuildWindowValues(7.0), 'memory_current' => 6.0,
            'memory_avg_month' => 7.0, 'ram_hours' => $this->pmssBuildWindowValues(8.0), 'tasks' => $this->pmssBuildWindowValues(9.0), 'tasks_current' => 9.0,
        ];
        $payload = $this->pmssBuildResourceStatsPayloadFromValues($values);
        $payload['ignored_field'] = ['month' => 999.0];
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/alice', $payload);
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
        $values = [
            'io_read' => $this->pmssBuildWindowValues(1.0), 'io_write' => $this->pmssBuildWindowValues(2.0), 'io_read_ops' => $this->pmssBuildWindowValues(3.0),
            'io_write_ops' => $this->pmssBuildWindowValues(4.0), 'cpu' => $this->pmssBuildWindowValues(5.0), 'memory' => $this->pmssBuildWindowValues(7.0), 'memory_current' => 6.0,
            'memory_avg_month' => 7.0, 'ram_hours' => $this->pmssBuildWindowValues(8.0), 'tasks' => $this->pmssBuildWindowValues(9.0), 'tasks_current' => 9.0,
        ];
        $expectedRow = $this->pmssBuildResourceReportRowFromValues($values);
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/alice', $this->pmssBuildResourceStatsPayloadFromValues($values));

        $this->assertEquals(
            ['users' => ['alice' => $expectedRow], 'totals' => $expectedRow, 'missing' => []],
            $this->pmssDecodeJsonArray($this->pmssRunRepoPhpScript('scripts/showResources.php', ['--json', '--user=alice'], ['PMSS_RUNTIME_DIR' => $runtimeDir]))
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
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/alice', $this->pmssBuildResourceStatsPayloadFromValues($this->sampleUsageValues([
            'memory_current' => INF,
        ])));
        $command = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/showResources.php',
            ['--json', '--user=alice'],
            ['PMSS_RUNTIME_DIR' => $runtimeDir],
            'pmss-show-stderr-'
        );
        $this->pmssAssertCommandFailsToStderr($command['result'], $command['stderrPath'], "Failed to encode resource report JSON.\n");
    }

}
