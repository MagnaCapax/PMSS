<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/iopsLimit.php';
require_once dirname(__DIR__, 2).'/user/iopsLimitEnforcer.php';

class IopsLimitHelpersTest extends TestCase
{
    public function testParsesValidMonthlyOperationValues(): void
    {
        foreach ([['123456', 123456], [' 42 OPS ', 42]] as $case) {
            $error = null;

            $this->assertSame($case[1], \pmssIopsLimitParseMonthlyOperations($case[0], $error));
            $this->assertSame(null, $error);
        }
    }

    public function testRejectsInvalidMonthlyOperationValues(): void
    {
        foreach ([['1.5', 'invalid format'], [true, 'missing value'], [-1.5, 'must be an integer']] as $case) {
            $error = null;

            $this->assertSame(null, \pmssIopsLimitParseMonthlyOperations($case[0], $error));
            $this->assertSame($case[1], $error);
        }
    }

    public function testReadsMonthlyIopsUsageFromSerializedResourcePayload(): void
    {
        $path = $this->pmssWriteTempFile('iops-resource-', serialize([
            'io_read_ops' => ['raw' => ['month' => 1200.4]],
            'io_write_ops' => ['raw' => ['month' => 799.6]],
        ]));

        $this->assertSame(2000, \pmssReadUserMonthlyIopsUsage($path));
    }

    public function testMonthlyIopsUsageIgnoresSentinelValues(): void
    {
        $path = $this->pmssWriteTempFile('iops-sentinel-resource-', serialize([
            'io_read_ops' => ['raw' => ['month' => '9223372036854775808']],
            'io_write_ops' => ['raw' => ['month' => '18446744073709551615']],
        ]));

        $this->assertSame(0, \pmssReadUserMonthlyIopsUsage($path));
    }

    public function testMonthlyIopsUsageKeepsPlausibleSideWhenOtherSideIsSentinel(): void
    {
        $path = $this->pmssWriteTempFile('iops-partial-sentinel-resource-', serialize([
            'io_read_ops' => ['raw' => ['month' => '9223372036854775808']],
            'io_write_ops' => ['raw' => ['month' => 1234.0]],
        ]));

        $this->assertSame(1234, \pmssReadUserMonthlyIopsUsage($path));
    }

    public function testTargetModePersistenceWritesRuntimeAndHomeFiles(): void
    {
        $root = $this->pmssMakeTempDir('pmss-iops-targets-');
        $runtimeRoot = $root.'/runtime';
        $homeRoot = $root.'/home';
        @mkdir($homeRoot.'/alice', 0755, true);

        $targets = \pmssIopsLimitTargetModes('alice', $homeRoot, $runtimeRoot);
        $runtimePath = array_key_first($targets);
        $error = null;

        $this->assertTrue(is_string($runtimePath) && $runtimePath !== '');
        $this->assertTrue(\pmssIntegerSettingStorageDirEnsure(dirname($runtimePath), 0700));
        $this->assertSame(0700, fileperms(dirname($runtimePath)) & 0777);
        $this->assertTrue(\pmssIntegerSettingTargetModesPersist($targets, 777, $error, 'invalid operations value'));
        $this->assertSame(null, $error);
        $this->assertSame('777', trim((string) file_get_contents($runtimeRoot.'/iopsLimits/alice')));
        $this->assertSame('777', trim((string) file_get_contents($homeRoot.'/alice/.iopsLimit')));
        $this->assertSame(0600, fileperms($runtimeRoot.'/iopsLimits/alice') & 0777);
        $this->assertSame(0664, fileperms($homeRoot.'/alice/.iopsLimit') & 0777);
    }

    public function testTargetModePersistenceRemovesZeroValuesWhenRequested(): void
    {
        $path = $this->pmssWriteTempFile('pmss-iops-remove-', '15');
        $error = null;

        $this->assertTrue(\pmssIntegerSettingTargetModesPersist([$path => 0600], 0, $error, 'invalid operations value', true));
        $this->assertSame(null, $error);
        $this->assertFalse(file_exists($path));
    }

    public function testIopsHelpersUseCanonicalRequireOnceDeclarations(): void
    {
        $guardNeedle = "if (!function_exists('pmss"."IopsLimit";
        $this->pmssAssertRepoFileNotContainsStrings(
            'scripts/lib/user/iopsLimit.php',
            [$guardNeedle]
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            'scripts/lib/user/iopsLimitEnforcer.php',
            [$guardNeedle]
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            'scripts/lib/user/integerSetting.php',
            ["if (!function_exists('pmss"."IntegerSetting"]
        );
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

        $this->assertTrue($command !== null);
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

    public function testPlanTransitions(): void
    {
        foreach ([[1000, 1001, false, 'enforce'], [1000, 1001, true, 'none'], [1000, 900, true, 'restore']] as $case) {
            $this->assertSame($case[3], \pmssIopsLimitEnforcementPlan($case[0], $case[1], $case[2])['action']);
        }
    }

    public function testRootCronSchedulesIopsLimits(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/root.cron',
            '/scripts/cron/iopsLimits.php',
            'root.cron should schedule iopsLimits.php hourly so monthly IOPS budget enforcer actually runs'
        );
    }
}
