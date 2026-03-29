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

        @mkdir($this->configDir.'/users', 0755, true);
        file_put_contents($this->configDir.'/users/alice.json', json_encode([
            'ramMiB' => 8192,
            'quota' => 4096,
            'quotaBurst' => 5120,
            'trafficLimit' => 0,
            'product' => 'M10G S',
        ]));

        file_put_contents($this->home.'/.quota', implode("\n", [
            'Disk quotas for user alice (uid 1000):',
            'Filesystem  blocks   quota   limit   grace',
            '/dev/md0  1.2T  4.0T  4.0T',
        ]));
        file_put_contents($this->home.'/.trafficData', serialize([
            'raw' => ['month' => 2150.0],
            'display' => ['month' => '2.1GiB'],
        ]));
        file_put_contents($this->home.'/.trafficDataIngress', serialize([
            'raw' => ['month' => 3680.0],
            'display' => ['month' => '3.6GiB'],
        ]));
        file_put_contents($this->home.'/.resourceData', serialize([
            'memory' => ['current' => 2147483648],
        ]));
        file_put_contents($this->home.'/.trafficLimit', "5\n");
        file_put_contents($this->home.'/.bonusTraffic', "1\n");

        file_put_contents($this->cgroupDir.'/memory.current', "2147483648\n");
        file_put_contents($this->cgroupDir.'/memory.max', "8589934592\n");
        file_put_contents($this->cgroupDir.'/pids.current', "12\n");
        file_put_contents($this->cgroupDir.'/cpu.stat', "usage_usec 42000000\n");
        file_put_contents($this->cgroupDir.'/io.stat', "8:0 rbytes=1024 wbytes=2048 rios=1 wios=2 dbytes=0 dios=0\n");
        file_put_contents(dirname($this->cgroupDir).'/io.pressure', "some avg10=1.5 avg60=0.5 avg300=0.1 total=10\n");
    }

    public function testCollectBuildsCanonicalStatsPayload(): void
    {
        $stats = \pmssStatsCollect([
            'user' => 'alice',
            'home' => $this->home,
            'config_dir' => $this->configDir,
            'cgroup_dir' => $this->cgroupDir,
            'version_file' => $this->pmssWriteVersionFile('2.8.14'),
            'socket_path' => $this->home.'/.rtorrent.socket',
        ], $this->rtorrentCallerStub());

        $this->assertEquals('alice', $stats['context']['user']);
        $this->assertEquals('M10G S', $stats['product']);
        $this->assertEquals('2.8.14', $stats['pmss_version']);
        $this->assertTrue($stats['disk']['percent'] > 0.0);
        $this->assertEquals(6144.0, $stats['traffic']['limit_mib']);
        $this->assertEquals(12, $stats['cgroup']['pids_current']);
        $this->assertEquals(6, $stats['rtorrent']['torrent_total']);
        $this->assertEquals(2, $stats['rtorrent']['torrent_downloading']);
        $this->assertEquals(2.0, $stats['rtorrent']['ratio']);
    }

    public function testRenderTextShowsCompactLayout(): void
    {
        $stats = \pmssStatsCollect([
            'user' => 'alice',
            'home' => $this->home,
            'config_dir' => $this->configDir,
            'cgroup_dir' => $this->cgroupDir,
            'version_file' => $this->pmssWriteVersionFile('2.8.14'),
        ], $this->rtorrentCallerStub());

        $rendered = \pmssStatsRenderText($stats, ['full' => false, 'mini' => false, 'no_header' => false]);
        $this->assertStringContainsString('Pulsed Media Seedbox · M10G S · alice', $rendered);
        $this->assertStringContainsString('Disk', $rendered);
        $this->assertStringContainsString('Memory', $rendered);
        $this->assertStringContainsString('Torrents', $rendered);
        $this->assertStringContainsString('Traffic', $rendered);
        $this->assertStringContainsString('PMSS 2.8.14', $rendered);
    }

    public function testRenderTextSupportsMiniMode(): void
    {
        $stats = \pmssStatsCollect([
            'user' => 'alice',
            'home' => $this->home,
            'config_dir' => $this->configDir,
            'cgroup_dir' => $this->cgroupDir,
        ], $this->rtorrentCallerStub());

        $rendered = \pmssStatsRenderText($stats, ['mini' => true, 'no_header' => true]);
        $lines = preg_split('/\r?\n/', trim($rendered)) ?: [];
        $this->assertEquals(4, count($lines));
        $this->assertStringContainsString('Disk', $lines[1]);
        $this->assertStringContainsString('Up', $lines[2]);
    }

    public function testRenderTextSupportsFullMode(): void
    {
        $stats = \pmssStatsCollect([
            'user' => 'alice',
            'home' => $this->home,
            'config_dir' => $this->configDir,
            'cgroup_dir' => $this->cgroupDir,
        ], $this->rtorrentCallerStub());

        $rendered = \pmssStatsRenderText($stats, ['full' => true, 'mini' => false, 'no_header' => true]);
        $this->assertStringContainsString('PIDs', $rendered);
        $this->assertStringContainsString('I/O Read', $rendered);
        $this->assertStringContainsString('I/O PSI', $rendered);
    }

    public function testMainEmitsJsonWhenRequested(): void
    {
        $versionFile = $this->pmssWriteVersionFile('3.0.0');
        $this->setEnv('PMSS_STATS_USER', 'alice');
        $this->setEnv('PMSS_STATS_HOME', $this->home);
        $this->setEnv('PMSS_STATS_CONFIG_DIR', $this->configDir);
        $this->setEnv('PMSS_STATS_CGROUP_DIR', $this->cgroupDir);
        $this->setEnv('PMSS_STATS_VERSION_FILE', $versionFile);

        ob_start();
        $rc = \pmssStatsMain(['scripts/pmss-stats.php', '--json']);
        $json = (string) ob_get_clean();

        $this->assertEquals(0, $rc);
        $this->assertStringContainsString('"pmss_version": "3.0.0"', $json);
        $this->assertStringContainsString('"product": "M10G S"', $json);
    }

    private function pmssWriteVersionFile(string $version): string
    {
        $path = $this->pmssMakeTempFile('pmss-stats-version-');
        file_put_contents($path, $version."\n");
        return $path;
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

    private function setEnv(string $key, string $value): void
    {
        putenv($key.'='.$value);
    }
}
