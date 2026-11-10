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

    private function numericUsageValues(): array
    {
        return [
            'io_read' => $this->pmssBuildWindowValues(1.0), 'io_write' => $this->pmssBuildWindowValues(2.0), 'io_read_ops' => $this->pmssBuildWindowValues(3.0),
            'io_write_ops' => $this->pmssBuildWindowValues(4.0), 'cpu' => $this->pmssBuildWindowValues(5.0), 'memory' => $this->pmssBuildWindowValues(7.0), 'memory_current' => 6.0,
            'memory_avg_month' => 7.0, 'ram_hours' => $this->pmssBuildWindowValues(8.0), 'tasks' => $this->pmssBuildWindowValues(9.0), 'tasks_current' => 9.0,
        ];
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

    public function testUserFilteredOutputFormatsCpuRamAndIops(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/fixtur01', $this->pmssBuildResourceStatsPayloadFromValues($this->sampleUsageValues()));
        $this->pmssAssertRepoPhpScriptOutputContains('scripts/showResources.php', ['--user=fixtur01'], ['IO Ops/mo', '70 ops', '1.0 hrs', '2.50 GB-hrs', '1.00 GiB', '2.00'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);
    }

    public function testUserFilteredOutputFormatsLargeByteAndIoOperationSuffixes(): void
    {
        foreach ([
            [['io_read_ops' => $this->pmssBuildWindowValues(960)], '1.00 K ops'],
            [['io_read_ops' => $this->pmssBuildWindowValues(9960)], '10.0 K ops'],
            [['io_read_ops' => $this->pmssBuildWindowValues(999999960)], '1.00 B ops'],
            [
                ['io_read' => $this->pmssBuildWindowValues(2 * 1024 * 1024 * 1024 * 1024, 1, 1, 1)],
                '2.00 TiB',
            ],
            [
                [
                    'io_read_ops' => $this->pmssBuildWindowValues(2500000, 30, 30, 3600),
                    'io_write_ops' => $this->pmssBuildWindowValues(3500000, 40, 40, 3600),
                ],
                '6.00 M ops',
            ],
        ] as [$overrides, $needle]) {
            $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
            $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/fixtur01', $this->pmssBuildResourceStatsPayloadFromValues($this->sampleUsageValues($overrides)));
            $this->assertStringContainsString($needle, $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--user=fixtur01'], ['PMSS_RUNTIME_DIR' => $runtimeDir]));
        }
    }

    public function testUserFilteredJsonOutputKeepsExpectedPayloadShape(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $values = $this->numericUsageValues();
        $payload = $this->pmssBuildResourceStatsPayloadFromValues($values);
        $payload['ignored_field'] = ['month' => 999.0];
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/fixtur01', $payload);
        $json = $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--json', '--user=fixtur01'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $payload = $this->pmssDecodeJsonArray($json);
        $this->assertEquals(6.0, $payload['users']['fixtur01']['memory']['current']);
        $this->assertEquals(8.0, $payload['users']['fixtur01']['ram_hours']['month']);
        $this->assertEquals(9.0, $payload['totals']['tasks']['current']);
        $this->assertEquals([], $payload['missing']);
        $this->assertTrue(!isset($payload['users']['fixtur01']['ignored_field']));
    }

    public function testUserFilteredJsonOutputMatchesSnapshot(): void
    {
        $values = $this->numericUsageValues();
        $expectedRow = $this->pmssBuildResourceReportRowFromValues($values);
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/fixtur01', $this->pmssBuildResourceStatsPayloadFromValues($values));

        $this->assertEquals(
            ['users' => ['fixtur01' => $expectedRow], 'totals' => $expectedRow, 'missing' => []],
            $this->pmssDecodeJsonArray($this->pmssRunRepoPhpScript('scripts/showResources.php', ['--json', '--user=fixtur01'], ['PMSS_RUNTIME_DIR' => $runtimeDir]))
        );
    }

    public function testUserFilteredJsonOutputMarksMissingWithoutRows(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        @mkdir($runtimeDir.'/resourceStats', 0755, true);

        $json = $this->pmssRunRepoPhpScript('scripts/showResources.php', ['--json', '--user=fixtur02'], ['PMSS_RUNTIME_DIR' => $runtimeDir]);

        $payload = $this->pmssDecodeJsonArray($json);
        $this->assertEquals([], $payload['users']);
        $this->assertEquals(0.0, $payload['totals']['memory']['current']);
        $this->assertEquals(['fixtur02'], $payload['missing']);
    }

    public function testJsonOutputReportsEncodingFailuresOnStderrOnly(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('pmss-show-runtime-');
        $this->pmssWriteSerializedFixture($runtimeDir.'/resourceStats/fixtur01', $this->pmssBuildResourceStatsPayloadFromValues($this->sampleUsageValues([
            'memory_current' => INF,
        ])));
        $command = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/showResources.php',
            ['--json', '--user=fixtur01'],
            ['PMSS_RUNTIME_DIR' => $runtimeDir],
            'pmss-show-stderr-'
        );
        $this->pmssAssertCommandFailsToStderr($command['result'], $command['stderrPath'], "Failed to encode resource report JSON.\n");
    }

}
