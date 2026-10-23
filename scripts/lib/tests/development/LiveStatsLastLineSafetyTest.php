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
            file_put_contents($root.'/log', $content);
            clearstatcache();
            $this->assertSame($expected, \pmssLiveStatsLastLine($root.'/log'));
        }
    }

    public function testInvalidPathsRetainUnavailableResult(): void
    {
        $root = $this->pmssMakeTempDir('pmss-live-tail-');
        file_put_contents($root.'/log', "latest\n");
        symlink($root.'/log', $root.'/link');
        foreach (['', $root, $root.'/missing', $root.'/link', "\0".$root.'/log', $root.'/log'."\0"] as $path) {
            $this->assertSame(null, \pmssLiveStatsLastLine($path));
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
        $script .= "require {$runtime};".$this->pmssInlinePhpLibraryInNamespace('scripts/lib/liveStats.php', 'LiveStatsTailFixture');
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
