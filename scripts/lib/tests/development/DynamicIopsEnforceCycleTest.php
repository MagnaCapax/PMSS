<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/DynamicIopsEnforceFixture.php';

/** Exercise enforced caps through the actual cron and writer without real sysfs. */
class DynamicIopsEnforceCycleTest extends TestCase
{
    use DynamicIopsEnforceFixture;

    public function testMdAndPlainHostsReachGuardedWriterAndPrivateAudit(): void
    {
        foreach ([false, true] as $md) {
            $row = $this->enforceCycle(['md' => $md]);
            $this->assertSame(1, count($row['writes']));
            $this->assertSame('8:0 750', $row['writes'][0][1]);
            $cycle = $row['logs']['PMSS_DYNAMIC_IOPS_LOG'][0];
            $this->assertSame('enforce', $cycle['mode']);
            $this->assertSame(false, $cycle['md_enforcement_excluded']);
            $this->assertSame('apply', $cycle['read_action']);
            $this->assertSame(['fixture' => 750], $cycle['read_caps']);
            $audit = $row['logs']['PMSS_CGROUP_APPLY_LOG'][0];
            $this->assertSame('dynamic-iops', $audit['source']);
            $this->assertSame(123, $audit['old']);
            $this->assertSame(750, $audit['new']);
            $this->assertSame('read_iops', $audit['control']);
        }
    }

    public function testFallbackKeepsKernelReadCapsAndContinuesLegacyWriteCaps(): void
    {
        foreach ([['capacity' => null], ['capacity' => 0.0], ['capacity' => PHP_FLOAT_MAX],
            ['badConfig' => true], ['pressure' => ['blend' => null]],
            ['source' => ['quota' => null, 'IOReadIOPS' => '/home:1000000', 'IOWriteIOPS' => '/home:60']]] as $options) {
            $row = $this->enforceCycle($options);
            $readPath = '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.read_iops_device';
            $this->assertSame('8:0 123', $row['kernel'][$readPath]);
            foreach ($row['writes'] as $write) $this->assertTrue(strpos($write[0], 'write_iops_device') !== false);
            $cycle = $row['logs']['PMSS_DYNAMIC_IOPS_LOG'][0];
            $this->assertSame('keep-kernel', $cycle['read_action']);
            $this->assertSame(null, $cycle['read_caps']);
            $this->assertTrue(strpos(implode("\n", $row['messages']), 'read_action=keep-kernel fallback=') !== false);
        }
    }

    public function testInvalidPolicyOrFailedLogCannotReleaseReadCaps(): void
    {
        foreach ([['settings' => ['quotaExponent' => 0.7]], ['settings' => ['curbMaxCut' => 2]],
            ['logFailure' => true], ['throw' => true]] as $options) {
            $row = $this->enforceCycle($options);
            $this->assertSame([], $row['writes']);
            $this->assertTrue(strpos(implode("\n", $row['messages']), 'keeping kernel read caps') !== false);
            $this->assertTrue(strpos(end($row['messages']), 'errors=1') !== false);
        }
    }

    public function testDryRunFlagAndEnvironmentWriteNeitherCapsNorLogs(): void
    {
        foreach ([[[], ['--dry-run']], [['envDryRun' => '1'], []]] as [$options, $args]) {
            $row = $this->enforceCycle($options, $args);
            $this->assertSame([], $row['writes']);
            $this->assertSame([], $row['logs']);
            $this->assertTrue(strpos($row['output'], '8:0 750') !== false);
        }
    }

    public function testOffRestoresLegacyValueAndAuditSource(): void
    {
        $enforced = $this->enforceCycle();
        $rolledBack = $this->enforceCycle(['mode' => 'off', 'kernel' => $enforced['kernel']]);
        $this->assertSame(1, count($rolledBack['writes']));
        $this->assertSame('8:0 1000000', $rolledBack['writes'][0][1]);
        $this->assertSame('cgroupIopsLimitApply.php', $rolledBack['logs']['PMSS_CGROUP_APPLY_LOG'][0]['source']);
        $this->assertFalse(isset($rolledBack['logs']['PMSS_DYNAMIC_IOPS_LOG']));
    }

    public function testOffClearsDynamicCapWhereNoLegacyCapApplies(): void
    {
        $source = ['quota' => 1000, 'IOWeight' => 100, 'IOWriteIOPS' => '/home:90'];
        $enforced = $this->enforceCycle(['md' => true, 'source' => $source]);
        $readPath = '/sys/fs/cgroup/blkio/user.slice/user-1001.slice/blkio.throttle.read_iops_device';
        $this->assertSame('8:0 750', $enforced['kernel'][$readPath]);
        $off = $this->enforceCycle(['md' => true, 'source' => $source, 'mode' => 'off', 'kernel' => $enforced['kernel']]);
        $this->assertSame([[$readPath, '8:0 0']], $off['writes']);
        $audit = $off['logs']['PMSS_CGROUP_APPLY_LOG'][0];
        $this->assertSame('dynamic-iops-off', $audit['source']);
        $this->assertSame(750, $audit['old']);
        $this->assertSame(0, $audit['new']);
        $again = $this->enforceCycle(['md' => true, 'source' => $source, 'mode' => 'off', 'kernel' => [$readPath => '']]);
        $this->assertSame([], $again['writes']);
    }

    public function testRemovedShadowModeEnforces(): void
    {
        $row = $this->enforceCycle(['mode' => 'shadow']);
        $this->assertSame('8:0 750', $row['writes'][0][1]);
        $this->assertSame('enforce', $row['logs']['PMSS_DYNAMIC_IOPS_LOG'][0]['mode']);
    }

    public function testRepeatedEnforcementIsIdempotent(): void
    {
        $first = $this->enforceCycle();
        $next = $this->enforceCycle(['kernel' => $first['kernel']]);
        $this->assertSame([], $next['writes']);
        $this->assertFalse(isset($next['logs']['PMSS_CGROUP_APPLY_LOG']));
        $this->assertSame(1, count($next['logs']['PMSS_DYNAMIC_IOPS_LOG']));
    }
}
