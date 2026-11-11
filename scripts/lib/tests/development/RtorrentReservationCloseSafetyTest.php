<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Close-fault rollback in scripts/lib/rtorrent/portReservation.php uses only memory streams. */
class RtorrentReservationCloseSafetyTest extends TestCase
{
    public function testRollbackRetriesLiveStreamsAndStillRemovesAfterRetryFaults(): void
    {
        $script = <<<'PHP'
namespace RtorrentReservationCloseFixture;
function pmssPathTargetIsSafe($path, $absolute) { return true; }
function pmssDirEnsureExists($path, $mode) { return true; }
function rand($start, $end) { return $start; }
function is_link($path) { return false; }
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function fclose($handle) {
    $attempt = ++$GLOBALS['closes'];
    $GLOBALS['events'][] = ['close', $attempt];
    $stage = $attempt === 1 ? $GLOBALS['firstStage'] : $GLOBALS['retryStage'];
    $fault = $attempt === 1 ? $GLOBALS['firstFault'] : $GLOBALS['retryFault'];
    if ($stage === 'before') throw $fault;
    \fclose($handle);
    if ($stage === 'after') throw $fault;
    return true;
}
function pmssRegularFilePathIsReadable($path) { $GLOBALS['events'][] = ['readable']; return true; }
function unlink($path) {
    $GLOBALS['events'][] = ['unlink', $path];
    if ($GLOBALS['unlinkFault'] !== null) throw $GLOBALS['unlinkFault'];
    return $GLOBALS['unlinkResult'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrent/portReservation.php', 'RtorrentReservationCloseFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_RESERVATION_CLOSE'), true) as [$firstStage, $firstType, $retryStage, $retryType, $unlinkType]) {
    $GLOBALS['firstStage'] = $firstStage; $GLOBALS['retryStage'] = $retryStage;
    $GLOBALS['firstFault'] = new $firstType('initial close');
    $GLOBALS['retryFault'] = $retryType === '' ? null : new $retryType('retry close');
    $GLOBALS['unlinkFault'] = in_array($unlinkType, ['RuntimeException', 'Error'], true) ? new $unlinkType('unlink') : null;
    $GLOBALS['unlinkResult'] = $unlinkType !== 'false';
    $GLOBALS['events'] = []; $GLOBALS['closes'] = 0; $GLOBALS['handle'] = null;
    $result = $caught = null;
    try { $result = pmssRtorrentPortReserve('/fixture/ports', 'scgi', 4000, 4000); }
    catch (\Throwable $error) { $caught = $error; }
    $expected = $GLOBALS['unlinkFault'] ?? ($firstStage === 'before' ? $GLOBALS['retryFault'] : null) ?? $GLOBALS['firstFault'];
    $rows[] = [$result, $caught === $expected, is_resource($GLOBALS['handle']), $GLOBALS['events']];
    // A repeated pre-close failure remains best-effort; release the fixture's stream after observing it.
    if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
}
echo json_encode($rows);
PHP;
        $cases = $expected = [];
        foreach (['before', 'after'] as $firstStage) {
            foreach (['RuntimeException', 'Error'] as $firstType) {
                foreach (['', 'before', 'after'] as $retryStage) {
                    foreach ($retryStage === '' ? [''] : ['RuntimeException', 'Error'] as $retryType) {
                        foreach (['true', 'false', 'RuntimeException', 'Error'] as $unlinkType) {
                            $cases[] = [$firstStage, $firstType, $retryStage, $retryType, $unlinkType];
                            $events = [['open', '/fixture/ports/scgi/4000', 'x'], ['close', 1]];
                            if ($firstStage === 'before') $events[] = ['close', 2];
                            $events[] = ['unlink', '/fixture/ports/scgi/4000'];
                            $expected[] = [null, true, $firstStage === 'before' && $retryStage === 'before', $events];
                        }
                    }
                }
            }
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script, [
            'PMSS_TEST_RESERVATION_CLOSE' => json_encode($cases),
        ]));
    }
}
