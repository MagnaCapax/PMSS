<?php
/** Verify report-only aggregate quota diagnostics with injected numeric reports. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/quotaSnapshot.php';

class QuotaSnapshotOverageTest extends TestCase
{
    public function setUp(): void
    {
        $path = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-quota-overage-'),
            "fixtur01:100000:65536\nfixtur01:100000:2\n1000:200000:2\n");
        $this->pmssTrackEnvOverrides(['PMSS_SUBUID_PATH' => $path]);
    }

    private function primary(): array
    {
        // Rounded display limits deliberately differ from the numeric source.
        return ['rc' => 0, 'timed_out' => false, 'lines' => [
            'Disk quotas for user fixtur01 (uid 1000):', ' /dev/example 10K 20K 30K 2 100 125',
        ]];
    }

    private function observe(array $rows, array $state = [], ?array $primary = null): array
    {
        $messages = [];
        $usage = $state + ['rc' => 0, 'timed_out' => false, 'lines' => array_merge([
            '*** Report for user quotas on device /dev/example',
        ], $rows)];
        $report = \pmssQuotaSnapshotAggregateReport('fixtur01', $primary ?? $this->primary(),
            self::pmssMakeCallbacks(['usage' => $usage])['usage'], $this->pmssMakeArrayLogger($messages));
        return [$report, $messages];
    }

    public function testLogsAggregateOverageUsingExactPrimaryHardLimit(): void
    {
        [$report, $messages] = $this->observe(['#1000 -- 10 20 25 2 100 125', '#100000 -- 30 0 0 3 0 0']);
        $this->assertSame(['Aggregate quota hard limit exceeded for fixtur01: '
            .'filesystem=/dev/example aggregate_kib=40 hard_limit_kib=25 subordinate_ids=1'], $messages);
        $this->assertSame(' /dev/example 40K 20K 30K 5 100 125', $report['lines'][1]);
        $this->assertSame(0, $report['rc']);
        $this->assertSame(['rc', 'timed_out', 'lines'], array_keys($report));
        $this->pmssAssertRepoFileContainsString('scripts/lib/quota/snapshotRefresh.php',
            'pmssQuotaSnapshotAggregateReport($thisUser, $quotaRun, null, $log)');
    }

    public function testUnderEqualUnlimitedAndMissingPrimaryLimitsStaySilent(): void
    {
        foreach ([[], ['#100000 -- 30 0 0 3 0 0'],
            ['#1000 -- 10 20 25 2 100 125', '#100000 -- 14 0 0 3 0 0'],
            ['#1000 -- 10 20 25 2 100 125', '#100000 -- 15 0 0 3 0 0'],
            ['#1000 -- 10 0 0 2 100 125', '#100000 -- 30 0 0 3 0 0'],
            ['#1000 -- 10 20 invalid 2 100 125', '#100000 -- 30 0 0 3 0 0'],
            ['#1000 -- 10 20 '.PHP_INT_MAX.'0 2 100 125', '#100000 -- 30 0 0 3 0 0'],
        ] as $rows) {
            $this->assertSame([], $this->observe($rows)[1]);
        }
    }

    public function testCountsOnlyDistinctSubordinateIdsHoldingUsage(): void
    {
        $rows = ['#1000 -- 10 20 25 2 100 125', '#100000 -- 30 0 0 3 0 0',
            '#100000 -- 30 0 0 3 0 0', '#100001 -- 0 0 0 1 0 0', '#100002 -- 0 0 0 0 0 0',
            '#200001 -- 7 0 0 0 0 0', '#165536 -- 999 0 0 9 0 0'];
        $this->assertSame(['Aggregate quota hard limit exceeded for fixtur01: '
            .'filesystem=/dev/example aggregate_kib=47 hard_limit_kib=25 subordinate_ids=3'],
            $this->observe($rows)[1]);
    }

    public function testExistingOverQuotaStatusAndLoggerOmissionKeepTheSnapshotContract(): void
    {
        $primary = $this->primary();
        $primary['rc'] = 1;
        $usage = ['rc' => 0, 'timed_out' => false, 'lines' => [
            '*** Report for user quotas on device /dev/example',
            '#1000 -- 10 20 25 2 100 125', '#100000 -- 30 0 0 3 0 0',
        ]];
        [$report, $messages] = $this->observe(array_slice($usage['lines'], 1), [], $primary);
        $this->assertSame(1, $report['rc']);
        $this->assertSame(1, count($messages));
        $this->assertSame($report, \pmssQuotaSnapshotAggregateReport('fixtur01', $primary,
            self::pmssMakeCallbacks(['usage' => $usage])['usage']));
    }

    public function testFailedTimedOutUnrecognizedAndOverflowingReportsStaySilent(): void
    {
        $rows = ['#1000 -- 10 20 25 2 100 125', '#100000 -- 30 0 0 3 0 0'];
        foreach ([['rc' => 2], ['rc' => 124, 'timed_out' => true], ['lines' => ['invalid report']]] as $state) {
            $this->assertSame([], $this->observe($rows, $state)[1]);
        }
        $this->assertSame([], $this->observe([
            '#1000 -- '.PHP_INT_MAX.' 20 25 2 100 125', '#100000 -- 30 0 0 3 0 0',
        ])[1]);
        foreach ([['rc' => 2], ['rc' => 124, 'timed_out' => true], ['lines' => ['bad header']]] as $state) {
            $this->assertSame([], $this->observe($rows, [], $state + $this->primary())[1]);
        }
    }

    public function testMultipleFilesystemsShareOneLineAndLaterFailureDiscardsIt(): void
    {
        $primary = $this->primary();
        $primary['lines'][] = ' /dev/other 10K 20K 30K 2 100 125';
        $rows = ['#1000 -- 10 20 25 2 100 125', '#100000 -- 30 0 0 3 0 0'];
        $message = $this->observe($rows, [], $primary)[1];
        $this->assertSame(1, count($message));
        $this->assertSame('Aggregate quota hard limit exceeded for fixtur01: '
            .'filesystem=/dev/example aggregate_kib=40 hard_limit_kib=25 subordinate_ids=1; '
            .'filesystem=/dev/other aggregate_kib=40 hard_limit_kib=25 subordinate_ids=1', $message[0]);
        $messages = [];
        \pmssQuotaSnapshotAggregateReport('fixtur01', $primary, static function ($filesystem) use ($rows) {
            return ['rc' => $filesystem === '/dev/other' ? 2 : 0, 'timed_out' => false,
                'lines' => array_merge(['*** Report for user quotas on device /dev/example'], $rows)];
        }, $this->pmssMakeArrayLogger($messages));
        $this->assertSame([], $messages);
    }

    public function testAccountsWithoutSubordinateRangesKeepTheirExistingReport(): void
    {
        $this->pmssWriteFile((string) getenv('PMSS_SUBUID_PATH'), '');
        $this->assertSame([$this->primary(), []], $this->observe(['#1000 -- 30 20 25 2 100 125']));
    }
}
