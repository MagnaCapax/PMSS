<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/log.php';
require_once dirname(__DIR__, 2).'/traffic.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class ResourceLogHelpersTest extends TestCase
{
    private function withFakeSystemctl(array $outputLines, callable $callback): void
    {
        $this->pmssWithPathPrefix(
            $this->pmssMakeLineOutputStub('systemctl', $outputLines, 'pmss-resource-systemctl-'),
            $callback
        );
    }

    private function makeRoot(): string
    {
        return $this->pmssMakeTempDir('pmss-resource-', 0700);
    }

    private function makeCounters(int $ioRead, int $ioWrite, int $cpuNsec, int $memory, int $tasks, int $ioReadOps = 0, int $ioWriteOps = 0): array
    {
        return [
            'io_read' => $ioRead,
            'io_write' => $ioWrite,
            'io_read_ops' => $ioReadOps,
            'io_write_ops' => $ioWriteOps,
            'cpu_nsec' => $cpuNsec,
            'memory' => $memory,
            'tasks' => $tasks,
        ];
    }

    private function assertDeltaFields(array $result, array $expected): void
    {
        foreach ($expected as $field => $value) {
            $this->assertEquals($value, $result['delta'][$field]);
        }
    }

    public function testEnsureDirRejectsRelative(): void
    {
        $this->assertTrue(!\pmssEnsureSafeDir('relative/path', 0700));
    }

    public function testEnsureDirCreatesDirectory(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/state';
        $this->assertTrue(\pmssEnsureSafeDir($path, 0700));
        $this->assertTrue(is_dir($path));
    }

    public function testEnsureDirRejectsSymlink(): void
    {
        $root = $this->makeRoot();
        $target = $root.'/target';
        @mkdir($target, 0700, true);
        $link = $root.'/link';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $this->assertTrue(!\pmssEnsureSafeDir($link, 0700));
    }

    public function testEnsureDirRejectsSymlinkedParentDirectory(): void
    {
        $root = $this->makeRoot();
        $target = $root.'/target';
        $this->pmssEnsureFixtureDirectory($target, 0700);

        $symlinkedParent = $root.'/state';
        $this->pmssCreateSymlinkOrSkip($target, $symlinkedParent);

        $this->assertTrue(!\pmssEnsureSafeDir($symlinkedParent.'/daily', 0700));
        $this->assertTrue(!is_dir($target.'/daily'), 'must not create directories via symlinked parent');
    }

    public function testTimestampedAppendHelperAndCronConsumers(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/resource.log';
        $this->assertTrue(\pmssAppendRootTimestampedLogEntry($path, ": 123\n"));
        $this->assertTrue((bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}: 123\n$/', (string) file_get_contents($path)));
        $this->pmssAssertRepoFileContainsString('scripts/cron/resourceLog.php', "pmssAppendRootTimestampedLogEntry(\$logDir.'/'.\$user");
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/resourceLog.php', "pmssAppendUserFile(\$logDir.'/'.\$user");
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/resourceLog.php', "@file_put_contents(\$logDir.'/'.\$user");
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficLog.php', 'pmssAppendRootTimestampedLogEntry(');
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficIngressLog.php', 'pmssAppendRootTimestampedLogEntry(');
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/trafficLog.php', "file_put_contents(\$logdir . \$thisUser");
        $this->pmssAssertRepoFileNotContainsString('scripts/cron/trafficIngressLog.php', "@file_put_contents(\$logDir.'/'.\$user");
    }

    public function testFiveMinuteLinkBudgetThresholdChecks(): void
    {
        $this->assertTrue(!\pmssResourceLogExceedsFiveMinuteLinkBudget(100, null));
        $this->assertTrue(!\pmssResourceLogExceedsFiveMinuteLinkBudget(200000000, 100.0));
        $this->assertTrue(\pmssResourceLogExceedsFiveMinuteLinkBudget(30000000000, 100.0));
    }

    public function testParseTrafficRuleReadsAcceptOwnerMatchLines(): void
    {
        $rule = \pmssTrafficParseOutputRule(
            '5 2048 ACCEPT all -- * * 0.0.0.0/0 185.148.0.0/22 owner UID match 1001'
        );

        $this->assertEquals(
            ['bytes' => 2048, 'destination' => '185.148.0.0/22', 'uid' => 1001],
            $rule
        );
    }

    public function testParseTrafficRuleRejectsHeadersAndMalformedOwnerMatches(): void
    {
        $cases = [
            'Chain OUTPUT (policy ACCEPT 2 packets, 512 bytes)',
            'pkts bytes target prot opt in out source destination',
            '5 2048 ACCEPT all -- * * 0.0.0.0/0 185.148.0.0/22 owner GID match 1001',
            '5 nope ACCEPT all -- * * 0.0.0.0/0 0.0.0.0/0 owner UID match 1001',
        ];

        foreach ($cases as $line) {
            $this->assertSame(null, \pmssTrafficParseOutputRule($line));
        }
    }

    public function testParseTrafficUsageAggregatesTrafficLocalnetsAndUnmatchedBytes(): void
    {
        $usage = implode("\n", [
            'Chain OUTPUT (policy ACCEPT 3 packets, 512 bytes)',
            'pkts bytes target prot opt in out source destination',
            '1 100 ACCEPT all -- * * 0.0.0.0/0 0.0.0.0/0 owner UID match 1000',
            '2 250 ACCEPT all -- * * 0.0.0.0/0 185.148.0.0/22 owner UID match 1000',
            '3 400 ACCEPT all -- * * 0.0.0.0/0 185.148.0.0/22 owner UID match 1001',
            '4 300 ACCEPT all -- * * 0.0.0.0/0 0.0.0.0/0 owner UID match 1001',
            '5 999 ACCEPT all -- * * 0.0.0.0/0 10.0.0.0/8 owner UID match 1001',
        ]);

        $parsed = \pmssTrafficParseOutputUsage($usage, ['185.148.0.0/22']);

        $this->assertEquals([1000 => 100, 1001 => 300], $parsed['traffic']);
        $this->assertEquals([1000 => 250, 1001 => 400], $parsed['local']);
        $this->assertEquals(512, $parsed['unmatched']);
    }

    public function testTrafficLogUsesSharedUsageParserInsteadOfTempFileGreps(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficLog.php', '$parsedUsage = pmssTrafficParseOutputUsage($usage, $localnets);');
        $this->pmssAssertRepoFileContainsString('scripts/lib/traffic.php', 'function pmssTrafficBudgetExceeded(');
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficLog.php', 'pmssTrafficBudgetExceeded(');
        $this->pmssAssertRepoFileContainsString('scripts/cron/trafficIngressLog.php', 'pmssTrafficBudgetExceeded(');
        $this->pmssAssertRepoFileNotContainsStrings(
            'scripts/cron/trafficLog.php',
            ['$thisUsageFile', 'grep "0.0.0.0/0', 'grep "Chain OUTPUT ("', 'unlink($thisUsageFile)']
        );
    }

    public function testUserValidationRejectsUppercase(): void
    {
        $this->assertTrue(!\pmssResourceUserIsValid('Alice'));
    }

    public function testManagedUidLookupRejectsInvalidUser(): void
    {
        $this->assertSame(null, \pmssResourceLogLookupManagedUid('Alice'));
    }

    public function testTrafficIngressLoadsResourceHelpersDirectly(): void
    {
        $legacyPath = dirname(__DIR__, 3).'/resources/'.'user'.'Helpers.php';

        $this->pmssAssertRepoFileContainsString('scripts/lib/traffic/ingress.php', "require_once __DIR__.'/../resources/log.php';");
        $this->assertTrue(!is_file($legacyPath), 'Expected the legacy resource helper shim to be removed');
    }

    public function testReadCountersParsesSystemctlOutput(): void
    {
        $this->withFakeSystemctl([
            'IOReadBytes=11',
            'IOWriteBytes=22',
            'IOReadOperations=33',
            'IOWriteOperations=44',
            'CPUUsageNSec=55',
            'MemoryCurrent=66',
            'TasksCurrent=77',
        ], function (): void {
            $counters = \pmssResourceLogReadCounters(1000);
            $this->assertTrue(is_array($counters));
            $this->assertEquals(11, $counters['io_read']);
            $this->assertEquals(22, $counters['io_write']);
            $this->assertEquals(33, $counters['io_read_ops']);
            $this->assertEquals(44, $counters['io_write_ops']);
            $this->assertEquals(55, $counters['cpu_nsec']);
            $this->assertEquals(66, $counters['memory']);
            $this->assertEquals(77, $counters['tasks']);
        });
    }

    public function testReadCountersReturnsNullWhenRequiredFieldMissing(): void
    {
        $this->withFakeSystemctl([
            'IOReadBytes=11',
            'IOWriteBytes=22',
            'CPUUsageNSec=55',
            'MemoryCurrent=66',
        ], function (): void {
            $this->assertTrue(\pmssResourceLogReadCounters(1000) === null);
        });
    }

    public function testReadCountersDefaultsMissingOpsToZero(): void
    {
        $this->withFakeSystemctl([
            'IOReadBytes=11',
            'IOWriteBytes=22',
            'CPUUsageNSec=55',
            'MemoryCurrent=66',
            'TasksCurrent=77',
        ], function (): void {
            $counters = \pmssResourceLogReadCounters(1000);
            $this->assertTrue(is_array($counters));
            $this->assertEquals(0, $counters['io_read_ops']);
            $this->assertEquals(0, $counters['io_write_ops']);
        });
    }

    public function testReadCountersReturnsNullWhenRequiredValueIsNotNumeric(): void
    {
        $this->withFakeSystemctl([
            'IOReadBytes=11',
            'IOWriteBytes=22',
            'CPUUsageNSec=55',
            'MemoryCurrent=oops',
            'TasksCurrent=77',
        ], function (): void {
            $this->assertTrue(\pmssResourceLogReadCounters(1000) === null);
        });
    }

    public function testReadMemoryBreakdownParsesMemoryStat(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/user.slice/user-1000.slice';
        @mkdir($path, 0755, true);
        file_put_contents($path.'/memory.stat', "anon 123\nslab 999\nfile 456\n");

        $breakdown = \pmssResourceLogReadMemoryBreakdown(1000, $root);

        $this->assertEquals(['memory_anon' => 123, 'memory_file' => 456], $breakdown);
    }

    public function testReadMemoryBreakdownReturnsNullWhenFieldsMissing(): void
    {
        $root = $this->makeRoot();
        $path = $root.'/user.slice/user-1000.slice';
        @mkdir($path, 0755, true);
        file_put_contents($path.'/memory.stat', "anon 123\nslab 456\n");

        $this->assertTrue(\pmssResourceLogReadMemoryBreakdown(1000, $root) === null);
    }

    public function testUpdateStateCreatesStateWhenMissing(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        $counters = $this->makeCounters(10, 20, 30, 4096, 7);

        $result = \pmssResourceLogUpdateState($statePath, $counters);

        $this->assertDeltaFields($result, ['io_read' => 10, 'io_write' => 20, 'io_read_ops' => 0, 'io_write_ops' => 0, 'cpu_nsec' => 30]);
        $this->assertEquals(4096, $result['state']['memory']);
        $this->assertEquals(7, $result['state']['tasks']);
        $this->assertTrue($result['state']['ts'] > 0);
        $this->assertTrue(is_file($statePath));
    }

    public function testUpdateStatePersistsMemoryBreakdownWhenAvailable(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        $counters = $this->makeCounters(10, 20, 30, 4096, 7) + ['memory_anon' => 1024, 'memory_file' => 2048];

        $result = \pmssResourceLogUpdateState($statePath, $counters);

        $this->assertEquals(1024, $result['state']['memory_anon']);
        $this->assertEquals(2048, $result['state']['memory_file']);
    }

    public function testUpdateStateComputesDeltaFromPrevious(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        file_put_contents($statePath, json_encode([
            'io_read' => 5,
            'io_write' => 2,
            'io_read_ops' => 10,
            'io_write_ops' => 20,
            'cpu_nsec' => 100,
            'memory' => 1,
            'tasks' => 1,
            'ts' => 123,
        ]));

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(8, 12, 150, 2048, 3, 16, 29));

        $this->assertDeltaFields($result, ['io_read' => 3, 'io_write' => 10, 'io_read_ops' => 6, 'io_write_ops' => 9, 'cpu_nsec' => 50]);
        $state = $this->pmssReadJsonArrayFile($statePath, []);
        $this->assertEquals(8, $state['io_read']);
        $this->assertEquals(12, $state['io_write']);
        $this->assertEquals(16, $state['io_read_ops']);
        $this->assertEquals(29, $state['io_write_ops']);
        $this->assertEquals(150, $state['cpu_nsec']);
        $this->assertEquals(2048, $state['memory']);
        $this->assertEquals(3, $state['tasks']);
    }

    public function testUpdateStateHandlesCounterReset(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        file_put_contents($statePath, json_encode([
            'io_read' => 100,
            'io_write' => 200,
            'cpu_nsec' => 300,
            'memory' => 1,
            'tasks' => 1,
            'ts' => 1,
        ]));

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(10, 20, 30, 1024, 2));

        $this->assertDeltaFields($result, ['io_read' => 10, 'io_write' => 20, 'cpu_nsec' => 30]);
    }

    public function testUpdateStateHandlesInvalidJson(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        file_put_contents($statePath, '{invalid json}');

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(4, 5, 6, 2048, 1));

        $this->assertDeltaFields($result, ['io_read' => 4, 'io_write' => 5, 'cpu_nsec' => 6]);
    }

    public function testUpdateStateIgnoresMalformedPreviousCounterFields(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        file_put_contents($statePath, json_encode([
            'io_read' => ['bad'],
            'io_write' => true,
            'cpu_nsec' => '12ns',
            'memory' => 1,
            'tasks' => 1,
            'ts' => 1,
        ]));
        $result = null;

        $this->pmssAssertNoPhpWarnings(function () use (&$result, $statePath): void {
            $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(10, 20, 30, 1024, 2));
        });

        $this->assertDeltaFields($result, ['io_read' => 10, 'io_write' => 20, 'cpu_nsec' => 30]);
    }

    public function testUpdateStateDefaultsMissingCountersWithoutWarnings(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        $result = null;

        $this->pmssAssertNoPhpWarnings(function () use (&$result, $statePath): void {
            $result = \pmssResourceLogUpdateState($statePath, ['memory' => 2048, 'tasks' => 3]);
        });

        $this->assertDeltaFields($result, ['io_read' => 0, 'io_write' => 0, 'cpu_nsec' => 0]);
        $this->assertEquals(2048, $result['state']['memory']);
        $this->assertEquals(3, $result['state']['tasks']);
    }

    public function testCounterStateUpdateDefaultsMissingDeltaFieldsWithoutWarnings(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/state.json';
        $result = null;

        $this->pmssAssertNoPhpWarnings(function () use (&$result, $statePath): void {
            $result = \pmssCounterStateUpdate($statePath, ['io_read' => 9], ['io_read', 'io_write']);
        });

        $this->assertDeltaFields($result, ['io_read' => 9, 'io_write' => 0]);
    }

    public function testUpdateStateReturnsDeltaWhenFileMissing(): void
    {
        $root = $this->makeRoot();
        $statePath = $root.'/missing/state.json';

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(9, 8, 7, 512, 1));

        $this->assertDeltaFields($result, ['io_read' => 9, 'io_write' => 8, 'cpu_nsec' => 7]);
        $this->assertTrue(!is_file($statePath));
    }

    public function testUpdateStateRejectsRelativeStatePath(): void
    {
        $statePath = 'relative/state.json';

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(9, 8, 7, 512, 1));

        $this->assertDeltaFields($result, ['io_read' => 9, 'io_write' => 8, 'cpu_nsec' => 7]);
        $this->assertTrue(!is_file($statePath));
    }

    public function testUpdateStateRejectsSymlinkStatePath(): void
    {
        $root = $this->makeRoot();
        $target = $root.'/target.json';
        file_put_contents($target, json_encode(['io_read' => 1]));
        $statePath = $root.'/state.json';
        $this->pmssCreateSymlinkOrSkip($target, $statePath);

        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(9, 8, 7, 512, 1));

        $this->assertDeltaFields($result, ['io_read' => 9, 'io_write' => 8, 'cpu_nsec' => 7]);
        $this->assertEquals(['io_read' => 1], $this->pmssReadJsonArrayFile($target, []));
    }

    public function testUpdateStateRejectsSymlinkedParentDirectory(): void
    {
        $root = $this->makeRoot();
        $targetDir = $root.'/target';
        $this->pmssEnsureFixtureDirectory($targetDir, 0700);

        $stateDir = $root.'/state';
        $this->pmssCreateSymlinkOrSkip($targetDir, $stateDir);

        $statePath = $stateDir.'/user.json';
        $result = \pmssResourceLogUpdateState($statePath, $this->makeCounters(9, 8, 7, 512, 1));

        $this->assertDeltaFields($result, ['io_read' => 9, 'io_write' => 8, 'cpu_nsec' => 7]);
        $this->assertTrue(!is_file($targetDir.'/user.json'), 'must not write state via symlinked parent');
    }
}
