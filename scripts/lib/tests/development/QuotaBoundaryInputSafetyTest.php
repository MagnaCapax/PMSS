<?php
/** Verify quota selector and header-path guards with intercepted I/O and memory streams. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class QuotaBoundaryInputSafetyTest extends TestCase
{
    public function testFilesystemSelectorsAreCheckedBeforeCaptureAndKeepReportCaching(): void
    {
        $script = <<<'PHP'
namespace QuotaSelectorInputFixture;
function pmssQuotaReportCapture($command) {
    $GLOBALS['commands'][] = $command;
    return $GLOBALS['report'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/quota/snapshotUsage.php',
            'QuotaSelectorInputFixture');
        $script .= <<<'PHP'
$GLOBALS['commands'] = [];
$GLOBALS['report'] = ['rc' => 0, 'lines' => ['fixture report'], 'timed_out' => false];
$invalid = [];
foreach (['', '-', '--help', '-a', "\0", "\0/dev/fixture0", "/dev/fi\0xture0",
    "/dev/fixture0\0", "/dev/fixture0\0suffix"] as $path) {
    $invalid[] = pmssQuotaSnapshotFilesystemReport($path);
}
$refusals = [$invalid, $GLOBALS['commands']];
$valid = [];
foreach (['/dev/fixture0', '/fixture/mount space', "/fixture/mount'quote",
    'relative/mount', '0', '/fixture/-mount'] as $index => $filesystem) {
    foreach ([0, 2, 124] as $rc) {
        $path = $filesystem.'/case-'.$rc;
        $GLOBALS['commands'] = [];
        $GLOBALS['report'] = ['rc' => $rc, 'lines' => ['fixture '.$index], 'timed_out' => $rc === 124];
        $first = pmssQuotaSnapshotFilesystemReport($path);
        // A cached failure or timeout must not launch a second probe either.
        $GLOBALS['report'] = ['rc' => 99, 'lines' => [], 'timed_out' => false];
        $valid[] = [$first, pmssQuotaSnapshotFilesystemReport($path), $GLOBALS['commands']];
    }
}
echo json_encode([$refusals, $valid]);
PHP;
        $expected = [];
        foreach (['/dev/fixture0', '/fixture/mount space', "/fixture/mount'quote",
            'relative/mount', '0', '/fixture/-mount'] as $index => $filesystem) {
            foreach ([0, 2, 124] as $rc) {
                $report = ['rc' => $rc, 'lines' => ['fixture '.$index], 'timed_out' => $rc === 124];
                $expected[] = [$report, $report,
                    ['LC_ALL=C repquota -u -n '.escapeshellarg($filesystem.'/case-'.$rc).' 2>&1']];
            }
        }
        $failure = ['rc' => 2, 'lines' => [], 'timed_out' => false];
        $this->assertSame([[array_fill(0, 9, $failure), []], $expected], $this->pmssRunInlinePhpJson($script));
    }

    public function testHeaderPathsAreCheckedBeforeFileOperationsAndKeepStreamOwnership(): void
    {
        // Exercise scripts/lib/quota/recovery.php without opening host paths.
        $script = <<<'PHP'
namespace QuotaHeaderPathInputFixture;
function is_file($path) { $GLOBALS['events'][] = ['is_file', $path]; return true; }
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function fread($handle, $length) {
    $GLOBALS['events'][] = ['read', $length];
    return hex2bin($GLOBALS['type'] === 'user' ? '111fc0d900000000' : '2719c0d900000000');
}
function fclose($handle) { $GLOBALS['events'][] = ['close']; return \fclose($handle); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/quota/recovery.php',
            'QuotaHeaderPathInputFixture');
        $script .= <<<'PHP'
$invalid = $valid = [];
foreach (['user', 'group'] as $type) {
    $GLOBALS['type'] = $type;
    foreach (['', "\0", "\0/fixture/aquota", "/fixture/aquota\0",
        "/fixture/aq\0uota", "/fixture/aquota\0suffix"] as $path) {
        $GLOBALS['events'] = [];
        $invalid[] = [pmssQuotaFileHeaderInvalid($path, $type), $GLOBALS['events']];
    }
    foreach (['/fixture/aquota', 'relative/aquota', '/fixture/quota space', "/fixture/quota'quote", '0'] as $path) {
        $GLOBALS['events'] = [];
        $result = pmssQuotaFileHeaderInvalid($path, $type);
        // Observe ownership before fixture cleanup could conceal a leaked stream.
        $valid[] = [$result, $GLOBALS['events'], is_resource($GLOBALS['handle'])];
        if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
    }
}
echo json_encode([$invalid, $valid]);
PHP;
        $expected = [];
        foreach (['user', 'group'] as $type) {
            foreach (['/fixture/aquota', 'relative/aquota', '/fixture/quota space', "/fixture/quota'quote", '0'] as $path) {
                $expected[] = [false, [['is_file', $path], ['open', $path, 'rb'], ['read', 8], ['close']], false];
            }
        }
        $this->assertSame([array_fill(0, 12, [false, []]), $expected], $this->pmssRunInlinePhpJson($script));
    }
}
