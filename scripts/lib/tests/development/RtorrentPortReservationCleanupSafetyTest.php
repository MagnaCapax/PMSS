<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise scripts/lib/rtorrent/portReservation.php without allocating real ports or files. */
class RtorrentPortReservationCleanupSafetyTest extends TestCase
{
    public function testExclusiveReservationsRollBackAfterCloseAndReadabilityFaults(): void
    {
        $script = <<<'PHP'
namespace RtorrentPortReservationCleanupFixture;
class RuntimeException extends \RuntimeException {}
function observe($phase) {
    $GLOBALS['events'][] = $phase;
    if (isset($GLOBALS['faults'][$phase])) throw $GLOBALS['faults'][$phase];
}
function pmssPathTargetIsSafe($path, $absolute) { return true; }
function pmssDirEnsureExists($path, $mode) { return true; }
function rand($start, $end) { return $start; }
function is_link($path) { return $GLOBALS['case'] === 'linked'; }
function fopen($path, $mode) {
    observe('open');
    $GLOBALS['open'] = [$path, $mode];
    if (in_array($GLOBALS['case'], ['open-failed', 'occupied'], true)) return false;
    return $GLOBALS['handle'] = \fopen('php://memory', 'w+');
}
function pmssPathExistsOrLink($path) { observe('exists'); return $GLOBALS['case'] === 'occupied'; }
function fclose($handle) {
    \fclose($handle);
    observe('close');
    return $GLOBALS['case'] !== 'close-false';
}
function pmssRegularFilePathIsReadable($path) {
    observe('readable');
    return $GLOBALS['case'] !== 'unreadable';
}
function unlink($path) {
    $GLOBALS['removed'][] = $path;
    observe('unlink');
    return $GLOBALS['case'] !== 'unlink-false';
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/rtorrent/portReservation.php', 'RtorrentPortReservationCleanupFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_PORT_RESERVATION_CLEANUP'), true) as [$case, $phase, $type, $cleanupType]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['events'] = $GLOBALS['removed'] = $GLOBALS['faults'] = [];
    $GLOBALS['handle'] = $GLOBALS['open'] = null;
    if ($phase !== '') $GLOBALS['faults'][$phase] = new $type($phase.' failure');
    if ($cleanupType !== '') $GLOBALS['faults']['unlink'] = new $cleanupType('unlink failure');
    $result = $caught = null;
    try { $result = pmssRtorrentPortReserve('/fixture/ports', 'scgi', 4000, 4000); }
    catch (\Throwable $error) { $caught = $error; }
    $expectedFault = $GLOBALS['faults']['unlink'] ?? $GLOBALS['faults'][$phase] ?? null;
    $rows[] = [$result, $caught === null ? null : $caught->getMessage(),
        $expectedFault === null || $caught === $expectedFault, is_resource($GLOBALS['handle']),
        $GLOBALS['events'], $GLOBALS['open'], $GLOBALS['removed']];
    // Record any leak before releasing a regressed implementation's fake descriptor.
    if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
}
echo json_encode($rows);
PHP;
        $cases = [];
        foreach (['success', 'close-false', 'unreadable', 'open-failed', 'occupied', 'linked'] as $case) {
            $cases[] = [$case, '', '', ''];
        }
        foreach (['RuntimeException', 'Error'] as $type) {
            $cases[] = ['success', 'open', $type, ''];
            $cases[] = ['open-failed', 'exists', $type, ''];
            $cases[] = ['unreadable', 'unlink', $type, ''];
            foreach (['close', 'readable'] as $phase) {
                foreach (['success', 'unlink-false'] as $case) $cases[] = [$case, $phase, $type, ''];
                foreach (['RuntimeException', 'Error'] as $cleanupType) $cases[] = ['success', $phase, $type, $cleanupType];
            }
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_PORT_RESERVATION_CLEANUP' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        foreach ($cases as $index => [$case, $phase, $type, $cleanupType]) {
            $events = [];
            $open = null;
            $removed = [];
            if ($case !== 'linked') {
                $events[] = 'open';
                if ($phase !== 'open') {
                    $open = ['/fixture/ports/scgi/4000', 'x'];
                    if (in_array($case, ['occupied', 'open-failed'], true)) {
                        $events[] = 'exists';
                        if ($case === 'occupied') $events = array_merge(...array_fill(0, 17, $events));
                    } else {
                        $events[] = 'close';
                        if ($phase !== 'close') $events[] = 'readable';
                        if (in_array($phase, ['close', 'readable', 'unlink'], true) || $case === 'unreadable') {
                            $events[] = 'unlink';
                            $removed[] = '/fixture/ports/scgi/4000';
                        }
                    }
                }
            }
            $message = $cleanupType !== '' ? 'unlink failure' : ($phase !== '' ? $phase.' failure'
                : (in_array($case, ['occupied', 'linked'], true) ? 'No available rTorrent scgi port reservation slots'
                    : (in_array($case, ['unreadable', 'open-failed'], true) ? 'Unable to reserve port file: /fixture/ports/scgi/4000' : null)));
            $this->assertSame([$message === null ? 4000 : null, $message, true, false, $events, $open, $removed],
                $rows[$index], implode(':', $cases[$index]));
        }
    }
}
