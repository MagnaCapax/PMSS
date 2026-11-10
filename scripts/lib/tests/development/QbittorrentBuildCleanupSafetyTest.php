<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentBuildProbe.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentBuildProbe.php';

/** Cleanup fault injection never starts a process, sends a signal or creates a profile. */
class QbittorrentBuildCleanupSafetyTest extends TestCase
{
    public function testBothProbesAttemptEveryCleanupAfterExceptionsAndErrors(): void
    {
        $script = <<<'PHP'
namespace BuildProbeCleanupFixture;
function operation($stage, $result = true) {
    $GLOBALS['events'][] = $stage;
    if ($GLOBALS['stage'] === $stage) throw $GLOBALS['failure'];
    return $result;
}
function sys_get_temp_dir() { return '/fixture'; }
function random_bytes($length) { return str_repeat('x', $length); }
function mkdir($path, $mode) { return true; }
function proc_open($command, $descriptors, &$pipes, $cwd, $env) {
    $GLOBALS['launch'] = [$command, $descriptors, $cwd, $env];
    if (!operation('launch', $GLOBALS['case'] !== 'launch-false')) return false;
    $pipes[1] = $GLOBALS['pipe'] = \fopen('php://memory', 'w+');
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function stream_set_blocking($pipe, $blocking) {
    $GLOBALS['blocking'] = $blocking;
    return operation('blocking', $GLOBALS['case'] !== 'blocking-false');
}
function proc_get_status($process) {
    $stage = $GLOBALS['statusCalls']++ === 0 ? 'initial-status' : 'poll-status';
    $complete = in_array($GLOBALS['case'], ['success', 'exit-failure', 'cached-exit'], true);
    return operation($stage, $GLOBALS['case'] === $stage.'-false' ? false :
        ['pid' => 4242, 'running' => !$complete,
            'exitcode' => $complete ? ($GLOBALS['case'] === 'exit-failure' ? 1 : 0) : -1]);
}
function stream_get_contents($pipe, $length) {
    return operation('read', $GLOBALS['case'] === 'oversized' ? str_repeat('x', 4097) : "qBittorrent v5.3.0\n");
}
function feof($pipe) { return true; }
function posix_kill($pid, $signal) {
    $GLOBALS['signals'][] = [$pid, $signal];
    return operation('group-signal', $GLOBALS['case'] !== 'cleanup-false');
}
function proc_terminate($process, $signal) {
    $GLOBALS['signals'][] = ['child', $signal];
    return operation('terminate', $GLOBALS['case'] !== 'cleanup-false');
}
function fclose($pipe) {
    operation('pipe-close');
    \fclose($pipe);
    return $GLOBALS['case'] !== 'cleanup-false';
}
function proc_close($process) {
    operation('reap');
    \fclose($process);
    return $GLOBALS['case'] === 'cached-exit' ? -1 : 0;
}
function profileClean($profile) {
    $GLOBALS['cleaned'] = $profile;
    operation('profile-clean');
}
PHP;
        foreach (['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'] as $probe) {
            $script .= \pmssTestingFunctionExport($probe, 'BuildProbeCleanupFixture', [$probe.'Clean' => 'profileClean']);
        }
        $script .= <<<'PHP'
$rows = [];
foreach (['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'] as $probe) {
    foreach (['success', 'exit-failure', 'cached-exit', 'timeout', 'oversized', 'cleanup-false',
        'launch-false', 'initial-status-false', 'blocking-false', 'poll-status-false',
        'launch', 'initial-status', 'blocking', 'clock', 'pause', 'poll-status',
        'read', 'group-signal', 'terminate', 'pipe-close', 'reap', 'profile-clean'] as $case) {
        $stage = in_array($case, ['launch', 'initial-status', 'blocking', 'clock', 'pause', 'poll-status',
            'read', 'group-signal', 'terminate', 'pipe-close', 'reap', 'profile-clean'], true)
            ? $case : null;
        foreach ($stage === null ? [null] : [new \RuntimeException($case), new \Error($case)] as $failure) {
            $GLOBALS['case'] = $case; $GLOBALS['stage'] = $stage; $GLOBALS['failure'] = $failure;
            $GLOBALS['events'] = $GLOBALS['signals'] = []; $GLOBALS['cleaned'] = null;
            $GLOBALS['pipe'] = $GLOBALS['process'] = $GLOBALS['blocking'] = null;
            $GLOBALS['statusCalls'] = 0;
            $caught = $result = null; $ticks = 0;
            try {
                $result = (__NAMESPACE__.'\\'.$probe)('/fixture/qbittorrent-nox', [
                    'clock' => static function () use (&$ticks): float {
                        return operation('clock', $ticks++ === 0 || $GLOBALS['case'] === 'pause' ? 0.0 : 3.0);
                    },
                    'pause' => static function (): void { operation('pause'); },
                ]);
            } catch (\Throwable $error) { $caught = $error; }
            $rows[] = [$probe, $case, $result, $caught === $failure, $GLOBALS['events'],
                $GLOBALS['signals'], $GLOBALS['cleaned'], $GLOBALS['launch'], $GLOBALS['blocking'],
                is_resource($GLOBALS['pipe']), is_resource($GLOBALS['process'])];
            // A throwing close stub leaves only its own memory resource for the fixture to release.
            foreach (['pipe', 'process'] as $resource) {
                if (is_resource($GLOBALS[$resource])) \fclose($GLOBALS[$resource]);
            }
        }
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(68, count($rows));
        $profile = '/fixture/pmss-qbt-probe-'.bin2hex(str_repeat('x', 8));
        foreach ($rows as [$probe, $case, $result, $sameFailure, $events, $signals, $cleaned, $launch,
            $blocking, $pipeLive, $processLive]) {
            $complete = in_array($case, ['success', 'exit-failure', 'cached-exit'], true);
            $acquired = !in_array($case, ['launch', 'launch-false'], true);
            $knownPid = $acquired && !in_array($case, ['initial-status', 'initial-status-false'], true);
            $stopStage = str_replace('-false', '', $case);
            $expectedEvents = [];
            // Failure at each setup boundary must skip every subsequent read/setup stage.
            foreach (['launch', 'initial-status', 'blocking', 'clock', 'read', 'poll-status'] as $stage) {
                $expectedEvents[] = $stage;
                if ($stage === $stopStage) break;
            }
            if (end($expectedEvents) === 'poll-status' && $stopStage !== 'poll-status' && !$complete && $case !== 'oversized') {
                $expectedEvents[] = 'clock';
                if ($case === 'pause') $expectedEvents[] = 'pause';
            }
            $expectedSignals = [];
            if ($acquired && !$complete) {
                if ($knownPid) { $expectedEvents[] = 'group-signal'; $expectedSignals[] = [-4242, 9]; }
                $expectedEvents[] = 'terminate'; $expectedSignals[] = ['child', 9];
            }
            if ($acquired) $expectedEvents = array_merge($expectedEvents, ['pipe-close', 'reap']);
            $expectedEvents[] = 'profile-clean';
            $expectedResult = in_array($case, ['success', 'cached-exit'], true) ? "qBittorrent v5.3.0\n" : null;
            $this->assertSame([$expectedResult, true, $expectedEvents, $expectedSignals, $profile],
                [$result, $sameFailure, $events, $signals, $cleaned], $probe.':'.$case);
            $this->assertSame($knownPid ? false : null, $blocking, $probe.':'.$case);
            $this->assertSame([$case === 'pipe-close', $case === 'reap'], [$pipeLive, $processLive], $probe.':'.$case);
            $this->assertSame('exec setsid '.escapeshellarg('/fixture/qbittorrent-nox').' --version', $launch[0]);
            $this->assertSame([0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $launch[1]);
            $this->assertSame($profile, $launch[2]);
            $this->assertSame([$profile, $profile.'/.config', $profile.'/.local/share', $profile.'/.cache'],
                [$launch[3]['HOME'], $launch[3]['XDG_CONFIG_HOME'], $launch[3]['XDG_DATA_HOME'], $launch[3]['XDG_CACHE_HOME']]);
        }
    }
}
