<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Text boundaries in scripts/lib/quota/snapshotReport.php; no quota commands run. */
class QuotaReportOutputSafetyTest extends TestCase
{
    public function testCorruptReportsFailWhileTextAndTimeoutContractsRemainIntact(): void
    {
        $script = <<<'PHP'
namespace QuotaReportOutputSafetyFixture;
function microtime($asFloat) { return array_shift($GLOBALS['clock']); }
function pmssLockChildClosePrefix() { return 'fixture-close-fds; '; }
function pmssCommandCapture($command, $timeout) {
    $GLOBALS['events'][] = [$command, $timeout];
    return $GLOBALS['response'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/quota/snapshotReport.php',
            'QuotaReportOutputSafetyFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_QUOTA_OUTPUT'), true) as [$stdout, $rc, $elapsed]) {
    $GLOBALS['clock'] = [100.0, 100.0 + $elapsed];
    $GLOBALS['events'] = [];
    $GLOBALS['response'] = ['rc' => $rc, 'stdout' => $stdout, 'stderr' => ''];
    $results[] = [pmssQuotaReportCapture('fixture-quota', 5), $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $header = 'Disk quotas for user fixtur01 (uid 1000):';
        $row = '  /dev/example 10K* 9K 20K 2 10 20';
        $text = $header."\n".$row."\n";
        $cases = $expected = [];
        // Even an intact header and valid rows cannot authorize a partly corrupt snapshot.
        foreach (["\0", "\0".$text, $text."\0", $header."\0\n".$row,
            $text."\0ignored\n", $header."\n  /dev/example 1\0K 9K 20K 2 10 20\n",
            "*** Report for user quotas on device /dev/example\n#1000 -- 10 0 0 2 0 0\0\n"] as $corrupt) {
            foreach ([0, 1, 2, 127, 137] as $rc) {
                $cases[] = [$corrupt, $rc, 1];
                $expected[] = ['rc' => $rc > 1 ? $rc : 2, 'lines' => [], 'timed_out' => false];
            }
        }
        // Literal zero, blank lines, indentation, CRLF and trailing whitespace keep their shapes.
        foreach ([['', []], ["\n\n", []], [" \t\r\n", ['']], ['0', ['0']],
            [$text, [$header, $row]], [$header."  \r\n".$row."\t\r\n", [$header, $row]],
            [$header."\n\n".$row."\n\n", [$header, '', $row]]] as [$stdout, $lines]) {
            foreach ([0, 1, 2, 124, 137] as $rc) {
                $cases[] = [$stdout, $rc, 1];
                $expected[] = ['rc' => $rc, 'lines' => $lines, 'timed_out' => false];
            }
        }
        // Deadline expiry takes precedence over corrupt or valid partial output, including at equality.
        foreach ([$text, $text."\0"] as $stdout) {
            foreach ([124, 137] as $rc) {
                foreach ([5, 6] as $elapsed) {
                    $cases[] = [$stdout, $rc, $elapsed];
                    $expected[] = ['rc' => $rc, 'lines' => [], 'timed_out' => true];
                }
            }
        }
        $results = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_QUOTA_OUTPUT' => json_encode($cases)]);
        $this->assertSame(count($cases), count($results));
        foreach ($expected as $index => $report) {
            $this->assertSame([$report, [['fixture-close-fds; fixture-quota', 5]]], $results[$index],
                'Quota output case '.$index);
        }
    }
}
