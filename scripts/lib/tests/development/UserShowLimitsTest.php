<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
define('PMSS_USER_SHOW_LIMITS_LIBRARY', true);
require_once dirname(__DIR__, 4).'/etc/skel/bin/userShowLimits';

final class UserShowLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        set_error_handler(function ($severity, $message) {
            if (error_reporting() & $severity) throw new \ErrorException($message, 0, $severity);
            return false;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $home = $this->pmssMakeTempDir('pmss-show-limits-');
        $slice = $home.'/slice';
        mkdir($slice);
        file_put_contents($slice.'/memory.stat', "total_rss 1073741824\ntotal_cache 536870912\n");
        file_put_contents($slice.'/memory.usage_in_bytes', "1610612736\n");
        file_put_contents($slice.'/memory.limit_in_bytes', "4294967296\n");
        file_put_contents($slice.'/pids.current', "12\n");
        file_put_contents($slice.'/pids.max', "max\n");
        file_put_contents($slice.'/cpu.shares', "1024\n");
        file_put_contents($slice.'/cpu.cfs_quota_us', "-1\n");
        file_put_contents($slice.'/cpu.cfs_period_us', "100000\n");
        file_put_contents($slice.'/blkio.throttle.read_bps_device', "8:0 1000000000\n");
        file_put_contents($slice.'/blkio.throttle.write_bps_device', "8:0 2000000\n");
        file_put_contents($slice.'/blkio.throttle.read_iops_device', "8:0 71\n");
        mkdir($home.'/.config');
        file_put_contents($home.'/.config/pmss-user.json', json_encode(array('quota' => 10, 'ramMiB' => 4096, 'dockerEnabled' => true, 'IOReadBW' => '/dev/sda:20M', 'IOReadIOPS' => '/dev/sda:71')));
        file_put_contents($home.'/.quota', "Disk quotas for user\nFilesystem blocks quota limit grace files quota limit grace\n/dev/mapper/data 1024 2048 3072 1 2 3\n");
        file_put_contents($home.'/.trafficLimit', "10\n");
        file_put_contents($home.'/.bonusTraffic', "20\n");
        file_put_contents($home.'/.trafficData', serialize(array('raw' => array('month' => 1024))));
        file_put_contents($home.'/.trafficDataIngress', serialize(array('raw' => array('month' => 500))));
        file_put_contents($home.'/.resourceData', serialize(array('ram_hours' => array('raw' => array('month' => 8)), 'cpu' => array('raw' => array('month' => 3600000000000)))));
        file_put_contents($home.'/debian-version', "11.10\n");
        file_put_contents($home.'/pmss-version', "12.15\n");
        mkdir($home.'/proc');
        file_put_contents($home.'/proc/uptime', "90060.00 0.00\n");
        return array('home' => $home, 'uid' => 1000, 'euid' => 1000, 'host' => "host\033[31mname", 'debian' => $home.'/debian-version', 'version' => $home.'/pmss-version', 'cgroups' => array('memory' => $slice, 'pids' => $slice, 'cpu' => $slice, 'io' => $slice), 'proc' => $home.'/proc', 'home_fs' => $home, 'now' => time(), 'cpu_count' => 4);
    }

    private function metric(array $data, string $section, string $name): array
    {
        foreach ($data['sections'][$section]['metrics'] as $metric) if ($metric['metric'] === $name) return $metric;
        throw new \RuntimeException('Missing metric: '.$section.'/'.$name);
    }

    public function testQuotaSnapshotVariantsAndMissing(): void
    {
        $o = $this->fixture();
        $path = $o['home'].'/.quota';
        $this->assertSame(1048576.0, \pmssUserShowLimitsCollect($o)['sections']['disk']['metrics'][2]['value']);
        file_put_contents($path, "/dev/long\n  2G* 1G 3G 6days 9* 8 10 23:59\n");
        $data = \pmssUserShowLimitsCollect($o);
        $this->assertSame(2147483648.0, $this->metric($data, 'disk', 'blocks used')['value']);
        $this->assertSame(9.0, $this->metric($data, 'disk', 'files used')['value']);
        unlink($path);
        $this->assertSame(null, $this->metric(\pmssUserShowLimitsCollect($o), 'disk', 'blocks used')['value']);
    }

    public function testRealPanelReadersAndUnlimitedCounters(): void
    {
        $o = $this->fixture();
        $data = \pmssUserShowLimitsCollect($o);
        $this->assertSame(1610612736.0, $this->metric($data, 'memory', 'memory')['value']);
        $this->assertSame(4294967296.0, $this->metric($data, 'memory', 'memory')['limit']);
        $this->assertSame(25.0, $this->metric($data, 'memory', 'memory pressure')['value']);
        $this->assertSame(null, $this->metric($data, 'processes', 'processes')['limit']);
        $this->assertSame(null, $this->metric($data, 'cpu', 'CPU quota enforced')['value']);
        $this->assertSame(21474836480.0, $this->metric($data, 'traffic', 'upload past 30 days')['limit']);
        $this->assertSame(20971520.0, $this->metric($data, 'disk I/O', 'IOReadBW configured')['value']);
        $this->assertSame('bytes_per_second', $this->metric($data, 'disk I/O', 'IOReadBW configured')['unit']);
        $this->assertSame('bytes_per_second', $this->metric($data, 'disk I/O', 'read bps enforced')['unit']);
        $this->assertSame('iops', $this->metric($data, 'disk I/O', 'IOReadIOPS configured')['unit']);
        $this->assertSame('iops', $this->metric($data, 'disk I/O', 'read IOPS enforced')['unit']);
        file_put_contents($o['cgroups']['memory'].'/memory.limit_in_bytes', "9223372036854771712\n");
        $this->assertSame(null, $this->metric(\pmssUserShowLimitsCollect($o), 'memory', 'memory')['limit']);
        file_put_contents($o['home'].'/.trafficLimit', "0\n");
        $this->assertSame(null, $this->metric(\pmssUserShowLimitsCollect($o), 'traffic', 'upload past 30 days')['limit']);
    }

    public function testMissingConfigAndHelperStayLocal(): void
    {
        $o = $this->fixture();
        unlink($o['home'].'/.config/pmss-user.json');
        $this->assertSame(null, $this->metric(\pmssUserShowLimitsCollect($o), 'disk', 'quota configured')['value']);
        $o['helper_paths']['webCgroupMemoryStatus.php'] = $o['home'].'/missing-panel-helper.php';
        $data = \pmssUserShowLimitsCollect($o);
        $this->assertSame(false, $data['sections']['memory']['available']);
        $this->assertSame(true, $data['sections']['traffic']['available']);
    }

    public function testJsonModesAndControlCharacters(): void
    {
        $o = $this->fixture();
        list(, $json) = $this->pmssCaptureStdout(function () use ($o) { \pmssUserShowLimitsMain(array('userShowLimits', '--json'), $o); });
        $this->assertTrue(is_array(json_decode($json, true)));
        $this->assertSame('11.10', $this->metric(json_decode($json, true), 'account', 'Debian version')['value']);
        $this->assertSame('12.15', $this->metric(json_decode($json, true), 'account', 'PMSS version')['value']);
        $this->assertTrue(strpos($json, '\\u001b') === false);
        list(, $jsonl) = $this->pmssCaptureStdout(function () use ($o) { \pmssUserShowLimitsMain(array('userShowLimits', '--jsonl'), $o); });
        foreach (explode("\n", trim($jsonl)) as $line) {
            $metric = json_decode($line, true);
            $this->assertSame(array('section', 'metric', 'value', 'limit', 'unit', 'percent', 'source'), array_keys($metric));
            if ($metric['metric'] === 'Debian version') $this->assertSame('11.10', $metric['value']);
        }
    }

    public function testHumanViewShowsSharedStatsAndConfiguredLimit(): void
    {
        $o = $this->fixture();
        file_put_contents($o['cgroups']['pids'].'/pids.current', "71\n");
        file_put_contents($o['cgroups']['pids'].'/pids.max', "16384\n");
        file_put_contents($o['home'].'/.resourceData', serialize(array('ram_hours' => array('raw' => array('month' => 0.086071551640828)), 'cpu' => array('raw' => array('month' => 5633041754)))));
        touch($o['home'].'/.quota', $o['now'] - 65);
        list(, $human) = $this->pmssCaptureStdout(function () use ($o) {
            \pmssUserShowLimitsMain(array('userShowLimits'), $o);
        }, array('NO_COLOR' => '1'));
        $this->assertTrue(strpos($human, 'Server (shared by all accounts)') !== false);
        $this->assertTrue(strpos($human, 'configured)') !== false);
        $this->assertTrue(strpos($human, "\033") === false);
        $this->assertTrue(strpos($human, '11.10 [proc]') !== false);
        $this->assertTrue(strpos($human, '12.15 [proc]') !== false);
        $this->assertTrue(strpos($human, '953.7 MiB/s enforced') !== false);
        $this->assertTrue(strpos($human, '20.0 MiB/s [account-config]') !== false);
        $this->assertTrue(strpos($human, '71 IOPS enforced') !== false);
        $this->assertTrue(strpos($human, '0.09 GiB-hours [resource-data]') !== false && strpos($human, '<0.01 hours [resource-data]') !== false);
        $this->assertTrue(strpos($human, '25% [') !== false);
        $this->assertTrue(strpos($human, '/ 100 percent enforced') === false);
        $this->assertTrue(strpos($human, '1d 1h 1m [proc]') !== false);
        $this->assertTrue(strpos($human, '1m [quota-snapshot]') !== false);
        $this->assertTrue(strpos($human, '71 / 16384 enforced') !== false);
        $this->assertTrue(strpos($human, 'count /') === false);
        $this->assertTrue((bool) preg_match('/CPU quota enforced\s+unlimited \[cgroup\]/', $human));
        $this->assertTrue(strpos($human, 'n/a [cgroup]') !== false);
        touch($o['home'].'/.quota', $o['now'] - 5);
        clearstatcache(true, $o['home'].'/.quota');
        list(, $human) = $this->pmssCaptureStdout(function () use ($o) { \pmssUserShowLimitsMain(array('userShowLimits'), $o); }, array('NO_COLOR' => '1'));
        $this->assertTrue(strpos($human, '5s [quota-snapshot]') !== false);
    }

    public function testCpuUnlimitedAndUnreadableRemainDistinct(): void
    {
        $o = $this->fixture();
        $cpu = $o['cgroups']['cpu'];
        file_put_contents($cpu.'/cpu.max', "max 100000\n");
        list(, $human) = $this->pmssCaptureStdout(function () use ($o) { \pmssUserShowLimitsMain(array('userShowLimits'), $o); }, array('NO_COLOR' => '1'));
        $this->assertTrue(strpos($human, 'CPU quota enforced') !== false);
        $this->assertTrue((bool) preg_match('/CPU quota enforced\s+unlimited \[cgroup\]/', $human));
        $this->assertSame(null, $this->metric(\pmssUserShowLimitsCollect($o), 'cpu', 'CPU quota enforced')['value']);
        unlink($cpu.'/cpu.max');
        unlink($cpu.'/cpu.cfs_quota_us');
        list(, $human) = $this->pmssCaptureStdout(function () use ($o) { \pmssUserShowLimitsMain(array('userShowLimits'), $o); }, array('NO_COLOR' => '1'));
        $this->assertTrue((bool) preg_match('/CPU quota enforced\s+n\/a \[cgroup\]/', $human));
    }

    public function testDeletedButOpenFilesAreCounted(): void
    {
        $o = $this->fixture();
        $this->assertSame(0.0, $this->metric(\pmssUserShowLimitsCollect($o), 'disk', 'held by deleted files')['value']);
        $o['proc'] = '/proc';
        $o['uid'] = posix_geteuid();
        $before = $this->metric(\pmssUserShowLimitsCollect($o), 'disk', 'held by deleted files')['value'];
        $path = $o['home'].'/held.bin';
        $handle = fopen($path, 'w+');
        fwrite($handle, str_repeat('x', 1048576));
        fflush($handle);
        unlink($path);
        $held = $this->metric(\pmssUserShowLimitsCollect($o), 'disk', 'held by deleted files')['value'];
        fclose($handle);
        $this->assertTrue($held - $before >= 1048576, 'held grew by '.($held - $before));
        $this->assertSame($before, $this->metric(\pmssUserShowLimitsCollect($o), 'disk', 'held by deleted files')['value']);
    }

    public function testRootRefusal(): void
    {
        $this->assertSame(false, \pmssUserShowLimitsRootCheck(0));
        $this->assertSame(true, \pmssUserShowLimitsRootCheck(1000));
    }
}
