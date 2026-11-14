<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/quotaSnapshot.php';

/** Numeric quota fixtures only: no host quota commands or account lookups. */
class QuotaSnapshotUsageTest extends TestCase
{
    public function setUp(): void
    {
        $this->pmssTrackEnvKeys(['PMSS_SUBUID_PATH', 'PMSS_SUBGID_PATH']);
        $path = $this->pmssMakeTempFile('pmss-quota-subuid-');
        $this->pmssWriteFile($path, "fixtur01:100000:65536\n1000:200000:2\nfixtur02:300000:2\n");
        putenv('PMSS_SUBUID_PATH='.$path);
        $path = $this->pmssMakeTempFile('pmss-quota-subgid-');
        $this->pmssWriteFile($path, "fixtur01:400000:65536\n");
        putenv('PMSS_SUBGID_PATH='.$path);
    }

    private function report(array $lines, int $rc = 0, bool $timeout = false): array
    {
        return ['rc' => $rc, 'lines' => $lines, 'timed_out' => $timeout];
    }

    private function primary(string $row = ' /dev/example 10K 20K 25K 2 100k 125k', int $rc = 0): array
    {
        return $this->report(['Disk quotas for user fixtur01 (uid 1000):', $row], $rc);
    }

    private function usage(): array
    {
        return $this->report([
            '*** Report for user quotas on device /dev/example',
            '#1000 -- 10 20 25 2 100000 125000',
            '#100000 -- 300 0 0 6 0 0',
            '#165535 -- 400 0 0 7 0 0',
            '#200001 -- 500 0 0 8 0 0',
            '#165536 -- 800 0 0 9 0 0',
            '#300000 -- 900 0 0 10 0 0',
        ]);
    }

    public function testBothSnapshotWritersUseTheSameAggregation(): void
    {
        foreach (['scripts/lib/user/traffic.php', 'scripts/lib/quota/snapshotRefresh.php'] as $path) {
            $this->pmssAssertRepoFileContainsString($path, 'pmssQuotaSnapshotAggregateReport(');
        }
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/quota/snapshotUsage.php', [
            'static $reports = [];', 'pmssQuotaReportCapture(',
            "'LC_ALL=C repquota -u -n '", 'escapeshellarg($filesystem)',
        ]);
    }

    public function testSumsEveryMatchingUidIncludingBothRangeBoundaries(): void
    {
        $calls = [];
        $result = \pmssQuotaSnapshotAggregateReport('fixtur01', $this->primary(), function ($filesystem) use (&$calls) {
            $calls[] = $filesystem;
            return $this->usage();
        });
        $this->assertSame($this->primary(' /dev/example 1210K 20K 25K 23 100k 125k'), $result);
        $this->assertSame(['/dev/example'], $calls);
    }

    public function testPreservesPrimaryLimitsGraceAndOverQuotaMarkers(): void
    {
        foreach (['none', '6days', '00:59'] as $grace) {
            $input = $this->primary(' /dev/example 10K* 20K 25K '.$grace.' 2* 100k 125k none', 1);
            $expected = $this->primary(' /dev/example 1210K* 20K 25K '.$grace.' 23* 100k 125k none', 1);
            $this->assertSame($expected, \pmssQuotaSnapshotAggregateReport('fixtur01', $input, function () { return $this->usage(); }));
        }
    }

    public function testEachFilesystemGetsItsOwnTotals(): void
    {
        $input = $this->primary();
        $input['lines'][] = ' /dev/other 1K 2K 3K 1k 2k 3k';
        $result = \pmssQuotaSnapshotAggregateReport('fixtur01', $input, function ($filesystem) {
            return $filesystem === '/dev/example' ? $this->usage() : $this->report([
                '*** Report for user quotas on device /dev/other', '#100000 -- 7 0 0 1 0 0',
            ]);
        });
        $this->assertSame(' /dev/example 1210K 20K 25K 23 100k 125k', $result['lines'][1]);
        $this->assertSame(' /dev/other 7K 2K 3K 1 2k 3k', $result['lines'][2]);
    }

    public function testWrappedDeviceRowsAreCombinedBeforeAggregation(): void
    {
        $input = $this->primary();
        $input['lines'] = [$input['lines'][0], ' /dev/example', '    10K 20K 25K 2 100k 125k'];
        $this->assertSame($this->primary(' /dev/example 1210K 20K 25K 23 100k 125k'),
            \pmssQuotaSnapshotAggregateReport('fixtur01', $input, function () { return $this->usage(); }));
    }

    public function testMissingUidRecordsMeanZeroAndGroupRangesDoNotDoubleCharge(): void
    {
        $result = \pmssQuotaSnapshotAggregateReport('fixtur01', $this->primary(), function () {
            return $this->report(['*** Report for user quotas on device /dev/example']);
        });
        $this->assertSame($this->primary(' /dev/example 0K 20K 25K 0 100k 125k'), $result);
        // Group-only allocations do not create additional file owners or additional bytes.
        $this->pmssWriteFile((string) getenv('PMSS_SUBUID_PATH'), '');
        $this->assertSame($this->primary(), \pmssQuotaSnapshotAggregateReport('fixtur01', $this->primary(), function () {
            throw new \RuntimeException('No subordinate UID usage should be queried');
        }));
    }

    public function testFailedUnrecognizedAndTimedOutReportsCannotPublishPartialTotals(): void
    {
        foreach ([$this->report([], 2), $this->report(['error']), $this->report([], 124, true)] as $usage) {
            $result = \pmssQuotaSnapshotAggregateReport('fixtur01', $this->primary(), static function () use ($usage) { return $usage; });
            $this->assertSame($this->report([], 2, $usage['timed_out']), $result);
        }
        foreach ([$this->report([], 2), $this->report([], 124, true), $this->report(['bad header'])] as $primary) {
            $this->assertSame($primary, \pmssQuotaSnapshotAggregateReport('fixtur01', $primary, static function () {
                throw new \RuntimeException('Failed primary probes must not query subordinate usage');
            }));
        }
    }

    public function testLaterFilesystemFailuresAndOverflowDiscardAllUpdatedRows(): void
    {
        $input = $this->primary();
        $input['lines'][] = ' /dev/other 1K 2K 3K 1 2 3';
        $result = \pmssQuotaSnapshotAggregateReport('fixtur01', $input, function ($filesystem) {
            return $filesystem === '/dev/example' ? $this->usage() : $this->report([], 2);
        });
        $this->assertSame($this->report([], 2), $result);
        $usage = $this->usage();
        $usage['lines'][1] = '#1000 -- '.PHP_INT_MAX.' 20 25 2 100000 125000';
        $this->assertSame($this->report([], 2), \pmssQuotaSnapshotAggregateReport('fixtur01', $this->primary(),
            static function () use ($usage) { return $usage; }));
    }

    public function testOverlapsDuplicatesEmptyRowsAndCounterBounds(): void
    {
        $rows = \pmssQuotaSnapshotParseRepquotaUserRows($this->usage()['lines']);
        $this->assertSame([1210, 23], \pmssQuotaSnapshotUsageSum(array_merge($rows, $rows), 1000,
            [[100000, 65536], [100000, 2], [200000, 2]]));
        $this->assertSame([0, 0], \pmssQuotaSnapshotUsageSum([], 1000, []));
        $this->assertSame([10, 2], \pmssQuotaSnapshotUsageSum($rows, 1000, []));
        foreach ([1, 4] as $column) {
            foreach (['-1', 'invalid', (string) PHP_INT_MAX.'0'] as $invalid) {
                $bad = $rows;
                $bad[0][$column] = $invalid;
                $this->assertSame(null, \pmssQuotaSnapshotUsageSum($bad, 1000, []));
            }
            $bad = $rows;
            $bad[0][$column] = (string) PHP_INT_MAX;
            $this->assertSame(null, \pmssQuotaSnapshotUsageSum($bad, 1000, [[100000, 65536]]));
        }
    }
}
