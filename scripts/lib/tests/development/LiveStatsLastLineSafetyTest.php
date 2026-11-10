<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/liveStats.php';

/** Log-tail failures must not trigger full reads or leak their open stream. */
class LiveStatsLastLineSafetyTest extends TestCase
{
    public function testTailSelectionPreservesCompleteLinesAndWhitespace(): void
    {
        $root = $this->pmssMakeTempDir('pmss-live-tail-');
        foreach ([
            '' => null,
            "\n\n" => null,
            'partial' => null,
            "first\npartial" => 'first',
            "first\n last \n\n" => ' last ',
            str_repeat("old line\n", 200)."latest\npartial" => 'latest',
        ] as $content => $expected) {
            $this->pmssWriteFile($root.'/log', $content);
            clearstatcache();
            $this->assertSame($expected, \pmssLiveStatsLastLine($root.'/log'));
        }
    }

    public function testInvalidPathsRetainUnavailableResult(): void
    {
        $root = $this->pmssMakeTempDir('pmss-live-tail-');
        $this->pmssWriteFile($root.'/log', "latest\n");
        symlink($root.'/log', $root.'/link');
        foreach (['', $root, $root.'/missing', $root.'/link', "\0".$root.'/log', $root.'/log'."\0"] as $path) {
            $this->assertSame(null, \pmssLiveStatsLastLine($path));
        }
    }

    public function testHardLinkedRegularLogRetainsTailResult(): void
    {
        $root = $this->pmssMakeTempDir('pmss-live-tail-');
        $this->pmssWriteFile($root.'/log', "first\nlatest\n");
        $this->assertTrue(link($root.'/log', $root.'/hardlink'));
        $this->assertSame('latest', \pmssLiveStatsLastLine($root.'/hardlink'));
    }

    public function testAcquisitionRefusesSubstitutedFilesBeforeReading(): void
    {
        $script = <<<'PHP'
namespace LiveStatsTailAcquisitionFixture;
function observe($phase, $value) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['case'] === $phase.'-throw') throw $GLOBALS['failure'];
    return $value;
}
function is_link($path) { return false; }
function is_file($path) { return true; }
function lstat($path) {
    $stat = ['dev' => 1, 'ino' => 2, 'mode' => 0100644, 'nlink' => 1];
    if ($GLOBALS['case'] === 'missing') $stat = false;
    if ($GLOBALS['case'] === 'not-regular') $stat['mode'] = 0010644;
    if ($GLOBALS['case'] === 'symlink') $stat['mode'] = 0120644;
    if ($GLOBALS['case'] === 'empty-metadata') $stat = [];
    if ($GLOBALS['case'] === 'hardlink') $stat['nlink'] = 2;
    return observe('lstat', $stat);
}
function fopen($path, $mode) {
    $GLOBALS['modes'][] = $mode;
    observe('open', null);
    if ($GLOBALS['case'] === 'open-failed') return false;
    $handle = \fopen('php://memory', 'w+');
    \fwrite($handle, "latest\n");
    \rewind($handle);
    return $GLOBALS['handle'] = $handle;
}
function fstat($handle) {
    $stat = \fstat($handle);
    $stat['dev'] = 1; $stat['ino'] = 2;
    if ($GLOBALS['case'] === 'inode-changed') $stat['ino'] = 3;
    if ($GLOBALS['case'] === 'device-changed') $stat['dev'] = 3;
    if ($GLOBALS['case'] === 'fifo-substituted') { $stat['ino'] = 3; $stat['mode'] = 0010644; }
    if ($GLOBALS['case'] === 'stat-failed') $stat = false;
    return observe('stat', $stat);
}
function filesize($path) { return observe('size', 7); }
function fseek($handle, $offset) { return observe('seek', \fseek($handle, $offset)); }
function stream_get_contents($handle, $length) { return observe('read', \stream_get_contents($handle, $length)); }
function fclose($handle) { \fclose($handle); return observe('close', true); }
PHP;
        // Exercise the shared inode matcher with injected metadata, rather than replacing it.
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/filesystem.php', 'LiveStatsTailAcquisitionFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/filesystemStream.php', 'LiveStatsTailAcquisitionFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/liveStats.php', 'LiveStatsTailAcquisitionFixture');
        $script .= <<<'PHP'
$results = [];
$cases = ['valid', 'hardlink', 'missing', 'not-regular', 'symlink', 'empty-metadata', 'open-failed',
    'stat-failed', 'inode-changed', 'device-changed', 'fifo-substituted', 'lstat-throw', 'open-throw', 'stat-throw'];
foreach ($cases as $case) {
    foreach (substr($case, -6) === '-throw' ? [new \RuntimeException($case), new \Error($case)] : [null] as $failure) {
        $GLOBALS['case'] = $case; $GLOBALS['failure'] = $failure;
        $GLOBALS['events'] = $GLOBALS['modes'] = [];
        $GLOBALS['handle'] = null;
        $result = $caught = null;
        try { $result = pmssLiveStatsLastLine('/fixture/log'); }
        catch (\Throwable $error) { $caught = $error; }
        $live = is_resource($GLOBALS['handle']);
        if ($live) \fclose($GLOBALS['handle']); // Measure leaks before fixture cleanup.
        $results[] = [$case, $result, $caught === $failure, $live, $GLOBALS['events'], $GLOBALS['modes']];
    }
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(17, count($results));
        foreach ($results as [$case, $result, $sameFailure, $live, $events, $modes]) {
            $success = in_array($case, ['valid', 'hardlink'], true);
            $opened = !in_array($case, ['missing', 'not-regular', 'symlink', 'empty-metadata', 'lstat-throw'], true);
            $acquired = $opened && !in_array($case, ['open-failed', 'open-throw'], true);
            $expected = ['lstat'];
            if ($opened) $expected[] = 'open';
            if ($acquired) $expected[] = 'stat';
            if ($success) $expected = array_merge($expected, ['size', 'seek', 'read']);
            if ($acquired) $expected[] = 'close';
            $this->assertSame([$success ? 'latest' : null, true, false, $expected, $opened ? ['rbn'] : []],
                [$result, $sameFailure, $live, $events, $modes], $case);
        }
    }

    public function testTailReadStaysBoundedWhenTheLogGrows(): void
    {
        // Append after size/seek, immediately before the real stream read.
        $script = <<<'PHP'
namespace LiveStatsTailGrowthFixture;
function stream_get_contents($handle, $length = -1) {
    $GLOBALS['length'] = $length;
    $GLOBALS['handle'] = $handle;
    \file_put_contents($GLOBALS['path'], $GLOBALS['append'], FILE_APPEND);
    return \stream_get_contents($handle, $length);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/liveStats.php', 'LiveStatsTailGrowthFixture');
        $script .= <<<'PHP'
[$GLOBALS['path'], $GLOBALS['append']] = json_decode(getenv('PMSS_TEST_LIVE_TAIL_GROWTH'), true);
$result = pmssLiveStatsLastLine($GLOBALS['path']);
echo json_encode([$result, $GLOBALS['length'], !is_resource($GLOBALS['handle'])]);
PHP;
        $path = $this->pmssMakeTempFile('pmss-live-tail-growth-');
        foreach ([
            ['', '', null],
            ["first\nlast\n", '', 'last'],
            ["first\npartial", '', 'first'],
            [str_repeat('x', 1023)."\n", '', str_repeat('x', 1023)],
            [str_repeat("old\n", 300)."latest\n", '', 'latest'],
            [str_repeat("old\n", 300)."latest\n", str_repeat("new\n", 1000), 'latest'],
            ["first\n", "second\n", 'second'],
            ["first\n", str_repeat('x', 1018)."\nnew\n", 'first'],
            ["first\n", str_repeat('x', 1017)."\nnew\n", str_repeat('x', 1017)],
        ] as [$content, $append, $expected]) {
            $this->pmssWriteFile($path, $content);
            $this->assertSame([$expected, 1024, true], $this->pmssRunInlinePhpJson($script, [
                'PMSS_TEST_LIVE_TAIL_GROWTH' => json_encode([$path, $append]),
            ]));
        }
    }

    public function testFailuresSkipLaterOperationsAndCloseTheStream(): void
    {
        foreach (['size', 'seek', 'read'] as $operation) {
            $this->assertSame([null, true, 1, false, $operation === 'size' ? 0 : 1, $operation === 'read' ? 1 : 0],
                $this->faultResult($operation, ''));
        }
    }

    public function testExceptionsAndErrorsPropagateAfterClosingTheStream(): void
    {
        foreach (['size', 'seek', 'read'] as $operation) {
            foreach (['RuntimeException', 'Error'] as $type) {
                $this->assertSame([null, true, 1, false, $operation === 'size' ? 0 : 1, $operation === 'read' ? 1 : 0],
                    $this->faultResult($operation, $type));
            }
        }
    }

    /** Inject failures in a child process so native filesystem functions stay untouched. */
    private function faultResult(string $operation, string $type): array
    {
        $runtime = var_export(dirname(__DIR__, 2).'/runtime.php', true);
        $script = <<<'PHP'
namespace LiveStatsTailFixture;
function is_link($path) { return false; }
function is_file($path) { return true; }
function lstat($path) { return ['dev' => 1, 'ino' => 2, 'mode' => 0100644]; }
function fstat($handle) { return lstat('/unused/log'); }
function fopen($path, $mode) { return $GLOBALS['stream'] = \fopen('php://temp', 'w+'); }
function failAt($operation, $success) {
    if ($GLOBALS['operation'] !== $operation) return $success;
    if ($GLOBALS['failure'] !== null) throw $GLOBALS['failure'];
    return $operation === 'seek' ? -1 : false;
}
function filesize($path) { return failAt('size', 2048); }
function fseek($handle, $offset) { ++$GLOBALS['seeks']; return failAt('seek', 0); }
function stream_get_contents($handle) { ++$GLOBALS['reads']; return failAt('read', "latest\n"); }
function fclose($handle) { ++$GLOBALS['closes']; return \fclose($handle); }
PHP;
        $script .= "require {$runtime};".$this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/filesystem.php', 'LiveStatsTailFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/runtime/filesystemStream.php', 'LiveStatsTailFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/liveStats.php', 'LiveStatsTailFixture');
        $script .= <<<'PHP'
$GLOBALS['operation'] = getenv('PMSS_TEST_LIVE_TAIL_OPERATION');
$type = getenv('PMSS_TEST_LIVE_TAIL_THROWABLE');
$GLOBALS['failure'] = $type === '' ? null : new $type('tail failure');
$GLOBALS['seeks'] = $GLOBALS['reads'] = $GLOBALS['closes'] = 0;
$result = $caught = null;
try { $result = pmssLiveStatsLastLine('/unused/log'); }
catch (\Throwable $error) { $caught = $error; }
echo json_encode([$result, $caught === $GLOBALS['failure'], $GLOBALS['closes'],
    is_resource($GLOBALS['stream']), $GLOBALS['seeks'], $GLOBALS['reads']]);
PHP;
        return $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_LIVE_TAIL_OPERATION' => $operation,
            'PMSS_TEST_LIVE_TAIL_THROWABLE' => $type,
        ]);
    }
}
