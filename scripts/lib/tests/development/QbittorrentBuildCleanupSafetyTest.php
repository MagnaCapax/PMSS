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
    $pipes[1] = $GLOBALS['pipe'] = \fopen('php://memory', 'w+');
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function stream_set_blocking($pipe, $blocking) { return true; }
function proc_get_status($process) {
    $complete = in_array($GLOBALS['case'], ['success', 'exit-failure', 'cached-exit'], true);
    return ['pid' => 4242, 'running' => !$complete,
        'exitcode' => $complete ? ($GLOBALS['case'] === 'exit-failure' ? 1 : 0) : -1];
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
        'read', 'group-signal', 'terminate', 'pipe-close', 'reap', 'profile-clean'] as $case) {
        $stage = in_array($case, ['read', 'group-signal', 'terminate', 'pipe-close', 'reap', 'profile-clean'], true)
            ? $case : null;
        foreach ($stage === null ? [null] : [new \RuntimeException($case), new \Error($case)] as $failure) {
            $GLOBALS['case'] = $case; $GLOBALS['stage'] = $stage; $GLOBALS['failure'] = $failure;
            $GLOBALS['events'] = $GLOBALS['signals'] = []; $GLOBALS['cleaned'] = null;
            $caught = $result = null; $ticks = 0;
            try {
                $result = (__NAMESPACE__.'\\'.$probe)('/fixture/qbittorrent-nox', [
                    'clock' => static function () use (&$ticks): float { return $ticks++ === 0 ? 0.0 : 3.0; },
                ]);
            } catch (\Throwable $error) { $caught = $error; }
            $rows[] = [$probe, $case, $result, $caught === $failure, $GLOBALS['events'],
                $GLOBALS['signals'], $GLOBALS['cleaned'], $GLOBALS['launch']];
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
        $this->assertSame(36, count($rows));
        $profile = '/fixture/pmss-qbt-probe-'.bin2hex(str_repeat('x', 8));
        foreach ($rows as [$probe, $case, $result, $sameFailure, $events, $signals, $cleaned, $launch]) {
            $complete = in_array($case, ['success', 'exit-failure', 'cached-exit'], true);
            $cleanup = ['pipe-close', 'reap', 'profile-clean'];
            $expectedEvents = array_merge(['read'], $complete ? [] : ['group-signal', 'terminate'], $cleanup);
            $expectedResult = in_array($case, ['success', 'cached-exit'], true) ? "qBittorrent v5.3.0\n" : null;
            $this->assertSame([$expectedResult, true, $expectedEvents,
                $complete ? [] : [[-4242, 9], ['child', 9]], $profile],
                [$result, $sameFailure, $events, $signals, $cleaned], $probe.':'.$case);
            $this->assertSame('exec setsid '.escapeshellarg('/fixture/qbittorrent-nox').' --version', $launch[0]);
            $this->assertSame([0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $launch[1]);
            $this->assertSame($profile, $launch[2]);
            $this->assertSame([$profile, $profile.'/.config', $profile.'/.local/share', $profile.'/.cache'],
                [$launch[3]['HOME'], $launch[3]['XDG_CONFIG_HOME'], $launch[3]['XDG_DATA_HOME'], $launch[3]['XDG_CACHE_HOME']]);
        }
    }
}
