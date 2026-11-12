<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/configureTorrentClientsVersion.php';

/** The switch lock tells a busy switch from a lock that cannot be created. */
final class TorrentClientsSwitchLockTest extends TestCase
{
    public function testLockOpensThenReportsABusySwitch(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-switch-lock-').'/torrentClients';
        [$lock, $refused] = \pmssConfigureTorrentClientLockOpen($dir, 'rtorrent', 'rtorrent');
        $this->assertTrue(is_resource($lock));
        $this->assertSame(null, $refused);
        [$second, $busy] = \pmssConfigureTorrentClientLockOpen($dir, 'rtorrent', 'rtorrent');
        $this->assertSame(null, $second);
        $this->assertSame(['ok' => false, 'status' => 409, 'message' => 'A rtorrent switch is already running.'], $busy);
        fclose($lock);
    }

    public function testUncreatableLockDirectoryIsNotReportedAsBusy(): void
    {
        $parent = $this->pmssMakeTempDir('pmss-switch-lock-ro-');
        $this->pmssWriteFile($parent.'/blocker', 'a file where the directory should be');
        [$lock, $refused] = \pmssConfigureTorrentClientLockOpen($parent.'/blocker/torrentClients', 'qbittorrent', 'qBittorrent');
        $this->assertSame(null, $lock);
        $this->assertSame(503, $refused['status']);
        $this->assertSame('The switch lock is unavailable.', $refused['message']);
    }

    public function testAcquisitionFaultsCloseOnlyStreamsNotTransferredToTheCaller(): void
    {
        // In-memory handles keep fault coverage independent of accounts and live switch locks.
        $script = <<<'PHP'
namespace TorrentSwitchLockFixture;
function is_dir($path) { return !in_array($GLOBALS['case'], ['created', 'mkdir-false'], true); }
function mkdir($path, $mode, $recursive) {
    $GLOBALS['events'][] = ['mkdir', $path, $mode, $recursive];
    return $GLOBALS['case'] !== 'mkdir-false';
}
function is_link($path) { return $GLOBALS['case'] === 'link'; }
function error_get_last() {
    if ($GLOBALS['case'] === 'quota') return ['message' => 'Disk quota exceeded'];
    if ($GLOBALS['case'] === 'space') return ['message' => 'No space left on device'];
    return null;
}
function fopen($path, $mode) {
    $GLOBALS['events'][] = ['open', $path, $mode];
    if ($GLOBALS['case'] === 'open-throw') throw $GLOBALS['failure'];
    return in_array($GLOBALS['case'], ['open-false', 'quota', 'space'], true)
        ? false : ($GLOBALS['handle'] = \fopen('php://memory', 'w+'));
}
function flock($handle, $operation) {
    $GLOBALS['events'][] = ['lock', $operation];
    if (in_array($GLOBALS['case'], ['lock-throw', 'lock-close-throw'], true)) throw $GLOBALS['failure'];
    return in_array($GLOBALS['case'], ['ok', 'created'], true);
}
function fclose($handle) {
    $GLOBALS['events'][] = ['close'];
    \fclose($handle);
    if (in_array($GLOBALS['case'], ['close-throw', 'lock-close-throw'], true)) throw $GLOBALS['closeFailure'];
    return $GLOBALS['case'] !== 'close-false';
}
PHP;
        foreach (['pmssConfigureTorrentClientLockOpen', 'pmssConfigureTorrentClientOutOfSpace'] as $name) {
            $script .= \pmssTestingFunctionExport($name, 'TorrentSwitchLockFixture');
        }
        $script .= <<<'PHP'
$rows = [];
foreach (['ok', 'created', 'mkdir-false', 'link', 'open-false', 'quota', 'space', 'busy', 'close-false',
    'open-throw', 'lock-throw', 'close-throw', 'lock-close-throw'] as $case) {
    foreach (['RuntimeException', 'Error'] as $kind) {
        $GLOBALS['case'] = $case; $GLOBALS['events'] = []; $GLOBALS['handle'] = null;
        $GLOBALS['failure'] = new $kind('acquisition fault');
        $GLOBALS['closeFailure'] = new $kind('close fault');
        $result = $caught = null;
        try { $result = pmssConfigureTorrentClientLockOpen('/fixture/locks', 'rtorrent', 'rTorrent'); }
        catch (\Throwable $error) { $caught = $error; }
        $expectedFailure = in_array($case, ['close-throw', 'lock-close-throw'], true) ? $GLOBALS['closeFailure']
            : (strpos($case, 'throw') !== false ? $GLOBALS['failure'] : null);
        $live = is_resource($GLOBALS['handle']);
        $transferred = is_array($result) && is_resource($result[0]) && $result[0] === $GLOBALS['handle'];
        if ($live) \fclose($GLOBALS['handle']);
        $rows[] = [$case, $caught === $expectedFailure, $live, $transferred,
            is_array($result) ? $result[1] : null, $GLOBALS['events']];
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(26, count($rows));
        foreach ($rows as [$case, $sameFailure, $live, $transferred, $refusal, $events]) {
            $success = in_array($case, ['ok', 'created'], true);
            $beforeOpen = in_array($case, ['mkdir-false', 'link'], true);
            $noStream = $beforeOpen || in_array($case, ['open-false', 'quota', 'space', 'open-throw'], true);
            $expected = [];
            if (in_array($case, ['created', 'mkdir-false'], true)) $expected[] = ['mkdir', '/fixture/locks', 0700, true];
            if (!$beforeOpen) $expected[] = ['open', '/fixture/locks/rtorrent.lock', 'ce'];
            if (!$noStream) {
                $expected[] = ['lock', LOCK_EX | LOCK_NB];
                if (!$success) $expected[] = ['close'];
            }
            $expectedRefusal = null;
            if (!$success && strpos($case, 'throw') === false) {
                $full = in_array($case, ['quota', 'space'], true);
                $expectedRefusal = ['ok' => false, 'status' => $full ? 507 : ($noStream ? 503 : 409),
                    'message' => $full
                        ? 'Your account is out of disk space, so the switch could not start. Nothing was changed. Free some space and try again.'
                        : ($noStream ? 'The switch lock is unavailable.' : 'A rTorrent switch is already running.')];
            }
            $this->assertSame([true, $success, $success, $expectedRefusal, $expected],
                [$sameFailure, $live, $transferred, $refusal, $events], $case);
        }
    }
}
