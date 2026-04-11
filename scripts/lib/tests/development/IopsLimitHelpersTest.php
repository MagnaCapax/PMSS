<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/iopsLimit.php';
require_once dirname(__DIR__, 2).'/user/iopsLimitEnforcer.php';

class IopsLimitHelpersTest extends TestCase
{
    public function testParsesPlainIntegerOps(): void
    {
        $error = null;
        $this->assertSame(123456, \pmssIopsLimitParseMonthlyOperations('123456', $error));
        $this->assertSame(null, $error);
    }

    public function testParsesOpsSuffixCaseInsensitive(): void
    {
        $error = null;
        $this->assertSame(42, \pmssIopsLimitParseMonthlyOperations(' 42 OPS ', $error));
        $this->assertSame(null, $error);
    }

    public function testRejectsFractionalOps(): void
    {
        $error = null;
        $this->assertSame(null, \pmssIopsLimitParseMonthlyOperations('1.5', $error));
        $this->assertSame('invalid format', $error);
    }

    public function testRejectsMissingFlagValue(): void
    {
        $error = null;
        $this->assertSame(null, \pmssIopsLimitParseMonthlyOperations(true, $error));
        $this->assertSame('missing value', $error);
    }

    public function testRejectsNegativeFloat(): void
    {
        $error = null;
        $this->assertSame(null, \pmssIopsLimitParseMonthlyOperations(-1.5, $error));
        $this->assertSame('must be an integer', $error);
    }

    public function testReadsMonthlyIopsUsageFromSerializedResourcePayload(): void
    {
        $path = $this->pmssWriteTempFile('iops-resource-', serialize([
            'io_read_ops' => ['raw' => ['month' => 1200.4]],
            'io_write_ops' => ['raw' => ['month' => 799.6]],
        ]));

        $this->assertSame(2000, \pmssReadUserMonthlyIopsUsage($path));
    }

    public function testBuildsThrottleCommandAgainstHomeDevice(): void
    {
        $command = \pmssIopsLimitBuildThrottleCommand('alice', 100);

        $this->assertStringContainsAllStrings([
            "'--device=/home'",
            "'--io-read-iops=/home:100'",
            "'--io-write-iops=/home:100'",
        ], $command);
    }

    public function testBuildsRestoreCommandFromStoredCgroupPayload(): void
    {
        $command = \pmssIopsLimitBuildRestoreCommand('alice', [
            'ramMiB' => 1024,
            'IOWeight' => 300,
            'IOReadIOPS' => '/dev/md0:250',
            'IOWriteIOPS' => '/dev/md0:275',
            'cpuQuotaPercent' => '125',
        ]);

        $this->assertNotSame(null, $command);
        $this->assertStringContainsAllStrings([
            "'/scripts/util/userConfigCgroup.php'",
            "'--wipe'",
            "'--memory-high=1024'",
            "'--io-weight=300'",
            "'--io-read-iops=/dev/md0:250'",
            "'--io-write-iops=/dev/md0:275'",
            "'--cpu-quota-percent=125'",
        ], (string) $command);
    }

    public function testRestoreCommandReturnsNullWithoutRamBaseline(): void
    {
        $this->assertSame(null, \pmssIopsLimitBuildRestoreCommand('alice', ['IOWeight' => 300]));
    }

    public function testPlanEnforcesOnlyOnFirstOveragePass(): void
    {
        $this->assertSame('enforce', \pmssIopsLimitEnforcementPlan(1000, 1001, false)['action']);
        $this->assertSame('none', \pmssIopsLimitEnforcementPlan(1000, 1001, true)['action']);
    }

    public function testPlanRestoresAfterBudgetFallsBackUnderLimit(): void
    {
        $this->assertSame('restore', \pmssIopsLimitEnforcementPlan(1000, 900, true)['action']);
    }
}
