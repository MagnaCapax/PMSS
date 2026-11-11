<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/cron/rtorrentPortReservationsReconcile.php';

/** Exercise scripts/cron/rtorrentPortReservationsReconcile.php with in-memory probes. */
class RtorrentReservationPreflightSafetyTest extends TestCase
{
    public function testPreflightReleasesDirectoryAndPreservesResultsAndExceptions(): void
    {
        $script = <<<'PHP'
namespace ReservationPreflightFixture;
function observe($phase) {
    $GLOBALS['events'][] = $phase;
    if ($GLOBALS['fault'] === $phase) throw $GLOBALS['failure'];
}
function is_dir($path) { return $GLOBALS['case'] !== 'missing-home'; }
function is_link($path) { return $GLOBALS['case'] === 'linked-home'; }
function opendir($path) {
    observe('open');
    return $GLOBALS['case'] === 'open-failed' ? false : ($GLOBALS['handle'] = \fopen('php://memory', 'w+'));
}
function pmssReadRegularFileContents($path) {
    observe('read');
    if ($GLOBALS['case'] === 'unreadable-passwd') return null;
    return $GLOBALS['case'] === 'invalid-passwd' ? '' : "root:x:0:0::/:/bin/sh\n";
}
function closedir($handle) {
    \fclose($handle);
    observe('close');
}
function pmssManagedHomeUsersList($includeSystem) { observe('users'); return ['fixtur01']; }
function pmssRtorrentPortReservationsReconcile($users) {
    observe('reconcile');
    return ['status' => $GLOBALS['case'] === 'reconcile-error' ? 'error' : 'ok'];
}
function date($format) { return 'fixture-time'; }
function pmssJsonEncodeSafe($payload) {
    observe('encode');
    return $GLOBALS['case'] === 'encode-failed' ? false : json_encode($payload);
}
PHP;
        // Export the actual cron controller; stubs own every filesystem and reconciliation boundary.
        $script .= \pmssTestingFunctionExport('pmssRtorrentPortReservationsReconcileMain', 'ReservationPreflightFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (json_decode(getenv('PMSS_TEST_RESERVATION_PREFLIGHT'), true) as [$case, $fault, $type]) {
    $GLOBALS['case'] = $case;
    $GLOBALS['fault'] = $fault;
    $GLOBALS['failure'] = $fault === '' ? null : new $type('fixture failure');
    $GLOBALS['handle'] = null;
    $GLOBALS['events'] = [];
    $result = $caught = null;
    ob_start();
    try { $result = pmssRtorrentPortReservationsReconcileMain(); }
    catch (\Throwable $error) { $caught = $error; }
    $rows[] = [$result, ob_get_clean(), $caught === $GLOBALS['failure'],
        is_resource($GLOBALS['handle']), $GLOBALS['events']];
    // Record a regressed implementation's leak before releasing its fake descriptor.
    if (is_resource($GLOBALS['handle'])) \fclose($GLOBALS['handle']);
}
echo json_encode($rows);
PHP;
        $cases = [];
        foreach (['missing-home', 'linked-home', 'open-failed', 'unreadable-passwd', 'invalid-passwd',
            'success', 'reconcile-error', 'encode-failed'] as $case) {
            $cases[] = [$case, '', ''];
        }
        foreach (['RuntimeException', 'Error'] as $type) {
            foreach (['read', 'close', 'users', 'reconcile', 'encode'] as $phase) {
                $cases[] = ['success', $phase, $type];
            }
            $cases[] = ['open-failed', 'read', $type];
        }
        $rows = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_RESERVATION_PREFLIGHT' => json_encode($cases)]);
        $this->assertSame(count($cases), count($rows));
        foreach ($cases as $index => [$case, $fault, $type]) {
            $skipped = in_array($case, ['missing-home', 'linked-home', 'open-failed', 'unreadable-passwd', 'invalid-passwd'], true);
            $events = in_array($case, ['missing-home', 'linked-home'], true) ? [] : ['open'];
            $events[] = 'read';
            if (!in_array($case, ['missing-home', 'linked-home', 'open-failed'], true)) $events[] = 'close';
            if (!$skipped && !in_array($fault, ['read', 'close'], true)) {
                foreach (['users', 'reconcile', 'encode'] as $phase) {
                    $events[] = $phase;
                    if ($fault === $phase) break;
                }
            }
            $output = $skipped ? '{"status":"skipped","reason":"user_enumeration_unavailable"}'
                : ($case === 'encode-failed' ? '{"status":"error","reason":"json_encode_failed"}'
                    : json_encode(['timestamp' => 'fixture-time', 'event' => 'rtorrent_port_reservations_reconcile',
                        'level' => $case === 'reconcile-error' ? 'error' : 'info',
                        'status' => $case === 'reconcile-error' ? 'error' : 'ok']));
            $this->assertSame([$fault === '' ? ($case === 'reconcile-error' ? 1 : 0) : null,
                $fault === '' ? $output."\n" : '', true, false, $events], $rows[$index], implode(':', $cases[$index]));
        }
    }
}
