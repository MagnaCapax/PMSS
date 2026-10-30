<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/pmssStats.php';

class PmssStatsCollectTest extends TestCase
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

        $this->pmssWriteRelativeFile($this->configDir, 'users/fixtur01.json', json_encode([
            'ramMiB' => 8192,
            'quota' => 4096,
            'quotaBurst' => 5120,
            'trafficLimit' => 0,
            'product' => 'M10G S',
        ]));

        foreach ([
            '.quota' => implode("\n", [
                'Disk quotas for user fixtur01 (uid 1000):',
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

        $this->pmssAssertArraySubsetSame(['user' => 'fixtur01'], $stats['context']);
        $this->pmssAssertArraySubsetSame(['product' => 'M10G S', 'pmss_version' => '2.8.14'], $stats);
        $this->assertEquals(30.0, round((float) $stats['disk']['percent'], 2));
        $this->pmssAssertArraySubsetSame(['limit_mib' => 6144.0], $stats['traffic']);
        $this->pmssAssertArraySubsetSame(['pids_current' => 12], $stats['cgroup']);
        $this->pmssAssertArraySubsetSame(['torrent_total' => 6, 'torrent_active' => 4, 'torrent_stopped' => 2, 'torrent_downloading' => 2, 'ratio' => 2.0], $stats['rtorrent']);
    }

    public function testRtorrentCountersRejectMalformedResponses(): void
    {
        foreach ([false, null, '', 'bad', [], -1, '1e309'] as $invalid) {
            $caller = static function (string $socketPath, string $method, array $params, int $timeout) use ($invalid) {
                return $method === 'get_down_rate' ? $invalid : 0;
            };
            $stats = \pmssStatsReadRtorrentStats($caller, '/unused');
            $this->assertFalse($stats['ok']);
            $this->assertSame(null, $stats['download_rate']);
        }

        $caller = static function (string $socketPath, string $method, array $params, int $timeout) {
            return $method === 'd.multicall2' ? [] : '0';
        };
        $stats = \pmssStatsReadRtorrentStats($caller, '/unused');
        $this->assertTrue($stats['ok']);
        $this->assertSame(0.0, $stats['download_rate']);
    }

    public function testRtorrentProbeExceptionsKeepOtherStatsAvailable(): void
    {
        $failingRate = static function (): void { throw new \RuntimeException('RPC failed'); };
        $stats = \pmssStatsReadRtorrentStats($failingRate, '/unused');
        $this->assertFalse($stats['ok']);
        $this->assertSame(null, $stats['upload_rate']);

        $failingView = static function (string $socketPath, string $method) {
            if ($method === 'd.multicall2') throw new \RuntimeException('RPC failed');
            return 0;
        };
        $stats = \pmssStatsReadRtorrentStats($failingView, '/unused');
        $this->assertTrue($stats['ok']);
        $this->pmssAssertArraySubsetSame(['upload_rate' => 0.0, 'torrent_total' => null], $stats);

        $payload = $this->collectStats([], $failingRate);
        $this->assertSame('M10G S', $payload['product']);
        $this->assertFalse($payload['rtorrent']['ok']);
    }

    public function testPercentHelperLocksMissingAndBoundedValues(): void
    {
        $this->assertSame(null, \pmssStatsPercent(null, 100.0));
        $this->assertSame(null, \pmssStatsPercent(10.0, 0.0));
        $this->assertEquals(25.0, \pmssStatsPercent(2.0, 8.0));
    }

    public function testPercentHelperRejectsNonFiniteInputsAndArithmeticOverflow(): void
    {
        foreach ([
            [INF, 1.0], [-INF, 1.0], [NAN, 1.0],
            [1.0, INF], [1.0, -INF], [1.0, NAN],
            [PHP_FLOAT_MAX, 0.5], [PHP_FLOAT_MAX, 1.0], [-PHP_FLOAT_MAX, 1.0],
        ] as [$used, $limit]) {
            $percent = \pmssStatsPercent($used, $limit);
            $this->assertSame(null, $percent);
            $this->assertSame('{"percent":null}', json_encode(['percent' => $percent]));
        }

        foreach ([
            [0.0, 1.0, 0.0], [2.0, 8.0, 25.0], [3.0, 2.0, 150.0],
            [-1.0, 2.0, -50.0], [PHP_FLOAT_MAX, PHP_FLOAT_MAX, 100.0],
        ] as [$used, $limit, $expected]) {
            // Preserve uncapped values and the historical sign of finite percentages.
            $this->assertSame($expected, \pmssStatsPercent($used, $limit));
        }
    }

    public function testRtorrentRatioOverflowKeepsCountersAndViewsAvailable(): void
    {
        foreach ([
            [PHP_FLOAT_MAX, 0.5, null], [1.0e200, 1.0e-200, null],
            [PHP_FLOAT_MAX, PHP_FLOAT_MAX, 1.0], [0.0, 0.5, 0.0],
            [1.0, 0.0, null], [1.0, 2.0, 0.5], [1.0e-300, 1.0e300, 0.0],
        ] as [$uploaded, $downloaded, $expected]) {
            $caller = static function (string $socketPath, string $method) use ($uploaded, $downloaded) {
                if ($method === 'get_up_total') return $uploaded;
                if ($method === 'get_down_total') return $downloaded;
                return $method === 'd.multicall2' ? [['hash']] : 0;
            };
            $stats = \pmssStatsReadRtorrentStats($caller, '/unused');
            $this->assertTrue($stats['ok']);
            $this->pmssAssertArraySubsetSame([
                'upload_total' => $uploaded, 'download_total' => $downloaded, 'ratio' => $expected,
                'torrent_total' => 1,
            ], $stats);
            $this->assertTrue(is_string(json_encode($stats)), 'Ratio arithmetic must not prevent JSON encoding');
        }
    }

    /**
     * Collect stats with the shared fixture paths used by this test case.
     *
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function collectStats(array $overrides = [], ?callable $rtorrentCaller = null): array
    {
        return \pmssStatsCollect(array_replace([
            'user' => 'fixtur01',
            'home' => $this->home,
            'config_dir' => $this->configDir,
            'cgroup_dir' => $this->cgroupDir,
        ], $overrides), $rtorrentCaller ?? $this->rtorrentCallerStub());
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
