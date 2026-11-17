<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/qbittorrentBuildProbe.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/qbittorrentBuildProbe.php';

/** Failed and malformed reads use only memory streams and fake process/profile operations. */
class QbittorrentBuildReadSafetyTest extends TestCase
{
    public function testBothProbesRejectReadFailuresWithoutConfusingEmptyReadsWithErrors(): void
    {
        $script = <<<'PHP'
namespace BuildProbeReadFixture;
function event($stage, $result = true) { $GLOBALS['events'][] = $stage; return $result; }
function sys_get_temp_dir() { return '/fixture'; }
function random_bytes($length) { return str_repeat('x', $length); }
function mkdir($path, $mode) { return true; }
function proc_open($command, $descriptors, &$pipes, $cwd, $env) {
    event('launch');
    $pipes[1] = $GLOBALS['pipe'] = \fopen('php://memory', 'w+');
    return $GLOBALS['process'] = \fopen('php://memory', 'w+');
}
function proc_get_status($process) {
    $running = !$GLOBALS['exited'] && $GLOBALS['readIndex'] < count($GLOBALS['chunks']);
    return event('status', ['pid' => 4242, 'running' => $running, 'exitcode' => $running ? -1 : 0]);
}
function stream_set_blocking($pipe, $blocking) { return event('blocking'); }
function stream_get_contents($pipe, $length) {
    $GLOBALS['limits'][] = $length;
    if (!array_key_exists($GLOBALS['readIndex'], $GLOBALS['chunks'])) {
        throw new \RuntimeException('Unexpected read after the fixture was exhausted');
    }
    return event('read', $GLOBALS['chunks'][$GLOBALS['readIndex']++]);
}
function feof($pipe) { return true; }
function posix_kill($pid, $signal) { return event('group-signal'); }
function proc_terminate($process, $signal) { return event('terminate'); }
function fclose($pipe) { event('pipe-close'); return \fclose($pipe); }
function proc_close($process) { event('reap'); \fclose($process); return 0; }
function profileClean($profile) { event('profile-clean'); }
PHP;
        foreach (['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'] as $probe) {
            $script .= \pmssTestingFunctionExport($probe, 'BuildProbeReadFixture', [$probe.'Clean' => 'profileClean']);
        }
        $script .= <<<'PHP'
$rows = [];
$version = "qBittorrent v5.3.0\n";
foreach (['pmssQbittorrentBuildProbe', 'pmssQbittorrentFrontendBuildProbe'] as $probe) {
    foreach ([
        ['failed-first', [false], false, null],
        ['failed-exited', [false], true, null],
        ['failed-after-version', [$version, false], false, null],
        ['failed-after-empty', ['', false], false, null],
        ['nul-only', ["\0"], false, null],
        ['nul-leading', ["\0".$version], false, null],
        ['nul-interior', ["qBittorrent\0 v5.3.0\n"], false, null],
        ['nul-trailing', [$version."\0"], false, null],
        ['nul-after-version', [$version, "\0"], false, null],
        ['nul-before-unread-chunk', ["\0", $version], false, null],
        ['nul-exited', [$version."\0"], true, null],
        ['empty-then-version', ['', $version], false, $version],
        ['empty-eof', [''], false, ''],
        ['success', [$version], false, $version],
        ['multiple-chunks', ['qBittorrent ', "v5.3.0\n"], false, $version],
        ['crlf', ["qBittorrent v5.3.0\r\n"], false, "qBittorrent v5.3.0\r\n"],
        ['other-control-bytes', ["\x01".$version."\x7f"], false, "\x01".$version."\x7f"],
    ] as [$name, $chunks, $exited, $expected]) {
        $GLOBALS['events'] = $GLOBALS['limits'] = [];
        $GLOBALS['chunks'] = $chunks; $GLOBALS['exited'] = $exited; $GLOBALS['readIndex'] = 0;
        try {
            $result = (__NAMESPACE__.'\\'.$probe)('/fixture/qbittorrent-nox', [
                'clock' => static function (): float { return 0.0; },
                'pause' => static function (): void { event('pause'); },
            ]);
            $rows[] = [$probe, $name, $chunks, $expected, $result, $GLOBALS['events'], $GLOBALS['limits'],
                is_resource($GLOBALS['pipe']), is_resource($GLOBALS['process'])];
        } finally {
            // A regression still releases the fixture's memory handles before exiting.
            foreach (['pipe', 'process'] as $resource) {
                if (is_resource($GLOBALS[$resource])) \fclose($GLOBALS[$resource]);
            }
        }
    }
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $this->assertSame(34, count($rows));
        foreach ($rows as $row) {
            [$probe, $name, $chunks, $expected] = $row;
            $expectedEvents = ['launch', 'status', 'blocking'];
            $expectedLimits = []; $bytes = 0;
            foreach ($chunks as $index => $chunk) {
                $expectedEvents[] = 'read';
                $expectedLimits[] = 4097 - $bytes;
                if ($chunk === false || strpos($chunk, "\0") !== false) break;
                $bytes += strlen($chunk);
                $expectedEvents[] = 'status';
                if ($index < count($chunks) - 1) $expectedEvents[] = 'pause';
            }
            // Failed or malformed reads stop before polling and release every acquired resource.
            if ($expected === null) $expectedEvents = array_merge($expectedEvents, ['group-signal', 'terminate']);
            $expectedEvents = array_merge($expectedEvents, ['pipe-close', 'reap', 'profile-clean']);
            $this->assertSame([$expected, $expectedEvents, $expectedLimits, false, false],
                array_slice($row, 4), $probe.':'.$name);
        }
    }
}
