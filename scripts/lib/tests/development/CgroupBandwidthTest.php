<?php
/** Verify opt-in bandwidth planning and guarded directBandwidth.php writes without host I/O. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/directBandwidth.php';

class CgroupBandwidthTest extends TestCase
{
    public function testDecimalUnitsAndRepresentableBoundaries(): void
    {
        foreach (['1' => 1, '001' => 1, '5K' => 5000, '5M' => 5000000, '2G' => 2000000000,
            '1T' => 1000000000000, (string) PHP_INT_MAX => PHP_INT_MAX] as $raw => $expected) {
            $this->assertSame(['/home', $expected], \pmssCgroupBandwidthSpecParse('/home:'.$raw));
        }
        $this->assertSame(['/dev/sda', 1000000], \pmssCgroupBandwidthSpecParse('/dev/sda:1M'));
        $boundary = intdiv(PHP_INT_MAX, 1000000000);
        $this->assertSame(['/home', $boundary * 1000000000], \pmssCgroupBandwidthSpecParse('/home:'.$boundary.'G'));
        $this->assertSame(null, \pmssCgroupBandwidthSpecParse('/home:'.($boundary + 1).'G'));
    }

    public function testMalformedSpecsNeverProduceCaps(): void
    {
        foreach ([null, false, 1, [], '', '/home:0', '/home:0M', '/home:-1', '/home:1.5M', '/home:1e6',
            '/home:1m', '/home:1MiB', '/home:infinity', '/home:'.str_repeat('9', 30), '/home:1M ',
            "/home:1M\n", "/home:1M\0", "/home\0:1M", 'label:1M', '/dev/../sda:1M', '/dev//sda:1M',
            '/dev/sda/:1M', '/dev/a b:1M', '/dev/:1M', '/home:1M:2'] as $raw) {
            $this->assertSame(null, \pmssCgroupBandwidthSpecParse($raw), var_export($raw, true));
        }
    }

    public function testPlanningRequiresBooleanOptInAndNeverSubstitutesExplicitDevices(): void
    {
        $json = ['IOReadBW' => '/home:5M', 'IOWriteBW' => '/dev/sda:10M'];
        $devices = [];
        $resolve = static function ($device) use (&$devices) { $devices[] = $device; return '8:0'; };
        foreach ([null, false, 0, 1, 'true', 'false', []] as $flag) {
            $this->assertSame([], \pmssCgroupBandwidthPlan($json, ['cgroup' => ['bandwidthEnforce' => $flag]], '9:0', $resolve));
        }
        $this->assertSame([], $devices);
        $policy = ['cgroup' => ['bandwidthEnforce' => true]];
        $this->assertSame([['read', '9:0', 5000000], ['write', '8:0', 10000000]],
            \pmssCgroupBandwidthPlan($json, $policy, '9:0', $resolve));
        $this->assertSame(['/dev/sda'], $devices);
        $this->assertSame([['read', '9:0', 5000000], ['write', '', 10000000]],
            \pmssCgroupBandwidthPlan($json, $policy, '9:0', static function () { return null; }));
        foreach ([[], ['IOReadBW' => ''], ['IOReadBW' => '/home:0'], ['IOWriteBW' => '/home:bad']] as $empty) {
            $this->assertSame([], \pmssCgroupBandwidthPlan($empty, $policy, '9:0', $resolve));
        }
        $shipped = require $this->pmssRepoPath('etc/seedbox/config/cgroup.policy.php');
        $this->assertSame(false, $shipped['cgroup']['bandwidthEnforce']);
    }

    private function observe(array $cases): array
    {
        $script = <<<'PHP'
namespace CgroupBandwidthFixture;
function pmssCgroupDirectWritableFileTarget($path) { $GLOBALS['events'][] = 'target'; return $GLOBALS['case']['target'] ?? true; }
function file_get_contents($path) { $GLOBALS['events'][] = 'read'; return $GLOBALS['case']['raw'] ?? "8:1 999\n8:0 100"; }
function file_put_contents($path, $value) { $GLOBALS['events'][] = ['write', $value]; return $GLOBALS['case']['bytes'] ?? strlen($value); }
PHP;
        $script .= $this->pmssInlinePhpFileWriteCompleteShim();
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/directBandwidth.php', 'CgroupBandwidthFixture');
        $script .= '$cases = '.var_export($cases, true).';';
        $script .= <<<'PHP'
$rows = [];
foreach ($cases as $case) {
    $GLOBALS['case'] = $case; $GLOBALS['events'] = [];
    $result = pmssCgroupBandwidthWriteThrottle($case['path'] ?? '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.read_bps_device',
        $case['device'] ?? '8:0', $case['rate'] ?? 5000000, $case['dry'] ?? false);
    $rows[] = [$result, $GLOBALS['events']];
}
echo json_encode($rows);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_DRY_RUN' => '0']);
    }

    public function testRejectedTargetsNeverReachFilesystem(): void
    {
        $cases = [['rate' => 0], ['rate' => -1], ['device' => ''], ['device' => "8:0\n"], ['device' => "8:0\0"]];
        foreach (['/tmp/fixture', '/sys/fs/cgroup/blkio/release_agent',
            '/sys/fs/cgroup/blkio/user.slice/user-0.slice/blkio.throttle.read_bps_device',
            '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.read_iops_device',
            '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/../blkio.throttle.read_bps_device'] as $path) $cases[] = ['path' => $path];
        foreach ($this->observe($cases) as $row) {
            $this->assertSame([['ok' => false, 'reason' => 'invalid-target', 'cur' => null], []], $row);
        }
    }

    public function testIdempotenceDryRunAndExactWriteResults(): void
    {
        $this->assertSame([[['ok' => true, 'reason' => 'already-set', 'cur' => "8:1 999\n8:0 5000000"], ['target', 'read']]],
            $this->observe([['raw' => "8:1 999\n8:0 5000000\n"]]));
        $this->assertSame('dry-run', $this->observe([['dry' => true]])[0][0]['reason']);
        $this->assertSame(['target', 'read'], $this->observe([['dry' => true]])[0][1]);
        $this->assertSame('unwritable', $this->observe([['target' => false]])[0][0]['reason']);
        $this->assertSame('unreadable', $this->observe([['raw' => false]])[0][0]['reason']);
        foreach ([false, 0, 1] as $bytes) $this->assertSame('write-failed', $this->observe([['bytes' => $bytes]])[0][0]['reason']);
        foreach (['read', 'write'] as $direction) {
            $row = $this->observe([['path' => '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.'.$direction.'_bps_device']])[0];
            $this->assertSame('written', $row[0]['reason']);
            $this->assertSame(['target', 'read', ['write', '8:0 5000000']], $row[1]);
        }
    }
}
