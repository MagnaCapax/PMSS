<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/dynamicIopsShadow.php';

/** Counterfactual shares and iowait are observations, never writer inputs. */
class DynamicIopsMonitoringTest extends TestCase
{
    public function testOldHistoryWithoutIowaitRetainsBlend(): void
    {
        $now = 1800000000; $samples = [];
        for ($n = 0; $n < 12; $n++) $samples[] = ['time' => $now - 300 * $n, 'psiFullAvg300' => 10];
        $old = \pmssDynamicIopsPressure($samples, $now);
        $this->assertSame(10.0, $old['blend']);
        $this->assertSame([5 => null, 15 => null, 60 => null], $old['iowait']);
        foreach ($samples as $n => &$sample) $sample['cpuIowaitPct'] = $n === 0 ? 12 : 0;
        unset($sample);
        $new = \pmssDynamicIopsPressure($samples, $now);
        $this->assertSame([5 => 12.0, 15 => 4.0, 60 => 1.0], $new['iowait']);
        $this->assertSame($old['blend'], $new['blend']);
        foreach ([null, -1, 101, INF, [], 'bad'] as $bad) {
            $samples[0]['cpuIowaitPct'] = $bad;
            $result = \pmssDynamicIopsPressure($samples, $now);
            $this->assertSame(null, $result['iowait'][5]);
            $this->assertSame($old['blend'], $result['blend']);
        }
    }

    public function testExplicitAccountsGetIndependentFloorChargeWithoutChangingProposals(): void
    {
        $users = ['small' => ['source' => ['quota' => 1000, 'resourceIOPSReadMin' => 1000,
            'resourceIOPSReadMax' => 100000, 'IOReadIOPS' => '/home:777'], 'weight' => 100],
            'large' => ['source' => ['quota' => 32000, 'resourceIOPSReadMax' => 100000], 'weight' => 100]];
        $allocate = static function (array $accounts, $blend = 15, $capacity = 3300): array {
            return \pmssDynamicIopsAllocate($accounts, [], ['blend' => $blend], $capacity, null, 'hdd', true);
        };
        $explicit = $allocate($users);
        unset($users['small']['source']['IOReadIOPS']);
        $unset = $allocate($users);
        foreach ($explicit['variants'] as $variant => $facts) {
            $this->assertSame(null, $facts['users']['small']['proposed']);
            foreach ($facts['users'] as $user => $row) {
                $this->assertSame($unset['variants'][$variant]['users'][$user]['proposed'], $row['would_be']);
            }
        }
        $this->assertSame(0.0, $explicit['variants']['1.0']['floor_lift']);
        $this->assertSame(800.0, $explicit['variants']['1.0']['would_be_floor_lift']);
        $this->assertSame(6400, $explicit['variants']['1.0']['users']['large']['proposed']);
        $this->assertSame(6200, $explicit['variants']['1.0']['users']['large']['would_be']);
        foreach ($unset['variants'] as $facts) foreach ($facts['users'] as $row) $this->assertSame($row['proposed'], $row['would_be']);
        foreach ([[$users, null, 3300], [$users, 15, null], [array_replace($users, ['missing' => ['source' => [], 'weight' => 100]]), 15, 3300]] as [$accounts, $blend, $capacity]) {
            $result = $allocate($accounts, $blend, $capacity);
            $this->assertTrue($result['fallback'] !== null);
            foreach ($result['variants'] as $facts) foreach ($facts['users'] as $row) $this->assertSame(null, $row['would_be']);
        }
    }

    public function testCycleRecordHasExplicitSchemaVersion(): void
    {
        $script = <<<'PHP'
namespace DynamicIopsSchemaFixture;
function pmssCgroupPolicyMountSourceResolve($mount) { return '/dev/fixture'; }
function pmssCgroupPolicyDeviceMajorMinorResolve($device) { return null; }
function pmssCgroupPolicyHomeDeviceIsMdBacked($device) { return false; }
function pmssCgroupPolicyHomeStorageClassResolve($device) { return 'hdd'; }
function pmssJsonFileReadAssoc($path, $safe) { return []; }
function pmssIoCeilingPublishedReadIops($path) { return null; }
function pmssPathTargetIsSafe(...$args) { return false; }
function pmssDynamicIopsAllocate(...$args) { return ['fallback' => 'missing-ceiling']; }
function pmssRunSnapshotLogTask($script, $env, $path, $callback) {
    $h = fopen('php://memory', 'w+');
    $rc = $callback($h); rewind($h); $GLOBALS['row'] = json_decode(stream_get_contents($h), true); fclose($h); return $rc;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/dynamicIopsShadow.php', 'DynamicIopsSchemaFixture');
        $script .= 'echo json_encode([pmssDynamicIopsShadowCycle([], ["cgroup" => ["dynamicIops" => ["mode" => "enforce"]]]), $GLOBALS["row"]["schema_version"]]);';
        $this->assertSame([true, 2], $this->pmssRunInlinePhpJson($script, ['PMSS_DRY_RUN' => '0']));
    }
}
