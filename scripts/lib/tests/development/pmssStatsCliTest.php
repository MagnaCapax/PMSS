<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/pmssStats.php';

class PmssStatsCliTest extends TestCase
{
    /** @var string */
    private $home;

    /** @var string */
    private $configDir;

    /** @var string */
    private $cgroupDir;

    public function setUp(): void
    {
        $this->home = $this->pmssMakeTempDir('pmss-stats-home-');
        $this->configDir = $this->pmssMakeTempDir('pmss-stats-config-');
        $this->cgroupDir = $this->pmssMakeTempDir('pmss-stats-cgroup-');

        $this->pmssWriteRelativeFile($this->configDir, 'users/alice.json', json_encode([
            'ramMiB' => 8192,
            'quota' => 4096,
            'quotaBurst' => 5120,
            'trafficLimit' => 0,
            'product' => 'M10G S',
        ]));

        foreach ([
            '.quota' => implode("\n", [
                'Disk quotas for user alice (uid 1000):',
                'Filesystem  blocks   quota   limit   grace',
                '/dev/md0  1.2T  4.0T  4.0T',
            ]),
            '.trafficData' => serialize([
                'raw' => ['month' => 2150.0],
                'display' => ['month' => '2.1GiB'],
            ]),
            '.trafficDataIngress' => serialize([
                'raw' => ['month' => 3680.0],
                'display' => ['month' => '3.6GiB'],
            ]),
            '.resourceData' => serialize([
                'memory' => ['current' => 2147483648],
            ]),
            '.trafficLimit' => "5\n",
            '.bonusTraffic' => "1\n",
        ] as $relativePath => $content) {
            $this->pmssWriteRelativeFile($this->home, $relativePath, $content);
        }

        foreach ([
            'memory.current' => "2147483648\n",
            'memory.max' => "8589934592\n",
            'pids.current' => "12\n",
            'cpu.stat' => "usage_usec 42000000\n",
            'io.stat' => "8:0 rbytes=1024 wbytes=2048 rios=1 wios=2 dbytes=0 dios=0\n",
        ] as $relativePath => $content) {
            $this->pmssWriteRelativeFile($this->cgroupDir, $relativePath, $content);
        }

        $this->pmssWriteFile(dirname($this->cgroupDir).'/io.pressure', "some avg10=1.5 avg60=0.5 avg300=0.1 total=10\n");
    }

    public function testCollectBuildsCanonicalStatsPayload(): void
    {
        $stats = $this->collectStats([
            'version_file' => $this->pmssWriteTempFile('stats-version', "2.8.14\n"),
            'socket_path' => $this->home.'/.rtorrent.socket',
        ]);

        $this->assertEquals('alice', $stats['context']['user']);
        $this->assertEquals('M10G S', $stats['product']);
        $this->assertEquals('2.8.14', $stats['pmss_version']);
        $this->assertTrue($stats['disk']['percent'] > 0.0);
        $this->assertEquals(6144.0, $stats['traffic']['limit_mib']);
        $this->assertEquals(12, $stats['cgroup']['pids_current']);
        $this->assertEquals(6, $stats['rtorrent']['torrent_total']);
        $this->assertEquals(4, $stats['rtorrent']['torrent_active']);
        $this->assertEquals(2, $stats['rtorrent']['torrent_stopped']);
        $this->assertEquals(2, $stats['rtorrent']['torrent_downloading']);
        $this->assertEquals(2.0, $stats['rtorrent']['ratio']);
    }

    public function testRenderTextShowsCompactLayout(): void
    {
        $stats = $this->collectStats([
            'version_file' => $this->pmssWriteTempFile('stats-version', "2.8.14\n"),
        ]);

        $rendered = \pmssStatsRenderText($stats, ['full' => false, 'mini' => false, 'no_header' => false]);
        $this->assertStringContainsAllStrings([
            'Pulsed Media Seedbox · M10G S · alice',
            'Disk',
            'Memory',
            'Torrents',
            'Traffic',
            'PMSS 2.8.14',
        ], $rendered);
    }

    public function testRenderTextSupportsMiniMode(): void
    {
        $stats = $this->collectStats();

        $rendered = \pmssStatsRenderText($stats, ['mini' => true, 'no_header' => true]);
        $lines = preg_split('/\r?\n/', trim($rendered)) ?: [];
        $this->assertEquals(4, count($lines));
        $this->assertStringContainsString('Disk', $lines[1]);
        $this->assertStringContainsString('Up', $lines[2]);
    }

    public function testRenderTextSupportsFullMode(): void
    {
        $stats = $this->collectStats();

        $rendered = \pmssStatsRenderText($stats, ['full' => true, 'mini' => false, 'no_header' => true]);
        $this->assertStringContainsAllStrings(['PIDs', 'I/O Read', 'I/O PSI'], $rendered);
    }

    public function testRenderPercentHelpersLockMissingAndBoundedValues(): void
    {
        $this->assertSame(null, \pmssStatsPercent(null, 100.0));
        $this->assertSame(null, \pmssStatsPercent(10.0, 0.0));
        $this->assertEquals(25.0, \pmssStatsPercent(2.0, 8.0));
        $this->assertSame('[····] n/a', \pmssStatsRenderPercentSuffix(null, 4));
        $this->assertSame('[████] 150%', \pmssStatsRenderPercentSuffix(150.0, 4));
    }

    public function testRenderTextSnapshotLocksStatsLayouts(): void
    {
        $stats = $this->statsRenderSnapshotPayload();

        foreach ([
            'default' => [
                ['full' => false, 'mini' => false, 'no_header' => false],
                'e35fbb16fd71c2c51701bbdfbfabf81d6bdf4adb201c68a208ecc33eafc58f9a',
            ],
            'mini' => [
                ['mini' => true, 'no_header' => true],
                'b692e46b01a5b3cc3600dcad006fae9aa7a9fad192f1064ec99e596b836fd849',
            ],
            'full' => [
                ['full' => true, 'mini' => false, 'no_header' => true],
                '17ca424972afa000d9e9f40f4e83e814c66b0df3d794774cfdf6f9ec2a765081',
            ],
            'no-limit' => [
                ['full' => false, 'mini' => false, 'no_header' => true, 'traffic_limit' => false],
                'e236f041095d915b5d84b68ceae5776de69093350efffbd04066aee22fc6a543',
            ],
        ] as $label => $case) {
            $payload = $stats;
            if (isset($case[0]['traffic_limit']) && !$case[0]['traffic_limit']) {
                unset($case[0]['traffic_limit']);
                $payload['traffic']['limit_mib'] = null;
                $payload['traffic']['percent'] = null;
            }

            $this->assertSame($case[1], hash('sha256', \pmssStatsRenderText($payload, $case[0])), $label);
        }
    }

    public function testHelpTextSnapshotLocksCliContract(): void
    {
        list($result, $help) = $this->pmssCaptureStdout(function () {
            return \pmssStatsParseOptions(['scripts/pmss-stats.php', '--help']);
        });

        $expected = "Usage: pmss-stats.php [--full] [--json] [--mini] [--no-header]\n\n";
        $expected .= "Options:\n";
        $expected .= "  --full       Show extra cgroup counters and I/O details.\n";
        $expected .= "  --json       Emit machine-readable JSON.\n";
        $expected .= "  --mini       Show a compact four-line summary.\n";
        $expected .= "  --no-header  Skip the title box.\n";
        $expected .= "  --help       Show this help.\n\n";

        $this->assertSame(false, $result);
        $this->assertSame($expected, $help);
    }

    public function testMainEmitsJsonWhenRequested(): void
    {
        $versionFile = $this->pmssWriteTempFile('stats-version', "3.0.0\n");
        $this->pmssTrackEnvOverrides([
            'PMSS_STATS_USER' => 'alice',
            'PMSS_STATS_HOME' => $this->home,
            'PMSS_STATS_CONFIG_DIR' => $this->configDir,
            'PMSS_STATS_CGROUP_DIR' => $this->cgroupDir,
            'PMSS_STATS_VERSION_FILE' => $versionFile,
        ]);

        list($rc, $json) = $this->pmssCaptureStdout(function (): int { return \pmssStatsMain(['scripts/pmss-stats.php', '--json']); });

        $this->assertEquals(0, $rc);
        $this->assertStringContainsAllStrings(['"pmss_version": "3.0.0"', '"product": "M10G S"'], $json);
    }

    public function testScriptReportsJsonEncodingFailuresOnStderrOnly(): void
    {
        $this->pmssWriteRelativeFile($this->home, '.resourceData', serialize([
            'memory' => ['current' => INF],
        ]));
        $versionFile = $this->pmssWriteTempFile('stats-version', "3.0.0\n");
        $command = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/pmss-stats.php',
            ['--json'],
            [
                'PMSS_STATS_USER' => 'alice',
                'PMSS_STATS_HOME' => $this->home,
                'PMSS_STATS_CONFIG_DIR' => $this->configDir,
                'PMSS_STATS_CGROUP_DIR' => $this->cgroupDir,
                'PMSS_STATS_VERSION_FILE' => $versionFile,
            ],
            'pmss-stats-stderr-'
        );
        $this->pmssAssertCommandFailsToStderr($command['result'], $command['stderrPath'], "Failed to encode PMSS stats JSON.\n");
    }

    /**
     * Build a deterministic payload for exact render-layout characterization.
     *
     * @return array<string, mixed>
     */
    private function statsRenderSnapshotPayload(): array
    {
        return [
            'context' => ['user' => 'alice'],
            'product' => 'M10G S',
            'pmss_version' => '2.8.14',
            'uptime_seconds' => 93784,
            'disk' => [
                'used_bytes' => 1288490188800.0,
                'limit_bytes' => 4398046511104.0,
                'used_text' => '1.2T',
                'limit_text' => '4.0T',
                'percent' => 29.296875,
            ],
            'memory' => [
                'current_bytes' => 2147483648.0,
                'limit_bytes' => 8589934592.0,
                'percent' => 25.0,
            ],
            'traffic' => [
                'upload_month_mib' => 2150.0,
                'download_month_mib' => 3680.0,
                'limit_mib' => 6144.0,
                'bonus_gib' => 1,
                'percent' => 34.993489583333,
            ],
            'resource' => [],
            'cgroup' => [
                'pids_current' => 12,
                'cpu_usage_usec' => 42000000,
                'io_read_bytes' => 1024,
                'io_write_bytes' => 2048,
                'io_pressure_avg10' => 1.5,
            ],
            'rtorrent' => [
                'ok' => true,
                'upload_rate' => 44302336.0,
                'download_rate' => 5347738.0,
                'upload_total' => 8796093022208.0,
                'download_total' => 4398046511104.0,
                'ratio' => 2.0,
                'torrent_total' => 6,
                'torrent_active' => 4,
                'torrent_seeding' => 2,
                'torrent_downloading' => 2,
                'torrent_stopped' => 2,
            ],
        ];
    }

    /**
     * Collect stats with the shared fixture paths used by this test case.
     *
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function collectStats(array $overrides = []): array
    {
        return \pmssStatsCollect(array_replace([
            'user' => 'alice',
            'home' => $this->home,
            'config_dir' => $this->configDir,
            'cgroup_dir' => $this->cgroupDir,
        ], $overrides), $this->rtorrentCallerStub());
    }

    /**
     * Build a deterministic rTorrent caller stub for hermetic stats tests.
     *
     * @return callable(string, string, array<int, mixed>, int): mixed
     */
    private function rtorrentCallerStub(): callable
    {
        return static function (string $socketPath, string $method, array $params, int $timeout) {
            unset($socketPath, $timeout);
            $responses = [
                'get_up_rate' => 44302336,
                'get_down_rate' => 5347738,
                'get_up_total' => 8796093022208,
                'get_down_total' => 4398046511104,
            ];
            if (isset($responses[$method])) {
                return $responses[$method];
            }

            if ($method === 'd.multicall2') {
                $view = $params[0] ?? '';
                $sizes = ['main' => 6, 'started' => 4, 'seeding' => 2];
                if (!isset($sizes[$view])) {
                    return false;
                }

                return array_fill(0, $sizes[$view], ['hash']);
            }

            if ($method === 'system.api_version') {
                return 10;
            }

            return false;
        };
    }
}
