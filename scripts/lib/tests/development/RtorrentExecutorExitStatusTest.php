<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Verify rTorrent executor exit codes, signal attribution and inherited output with private children. */
final class RtorrentExecutorExitStatusTest extends TestCase
{
    private function script(string $command, string $shims = ''): string
    {
        $source = $this->pmssReadRepoFile('etc/skel/.rtorrentExecute.php');
        $start = strpos($source, '  $rtorrentRc = -1;');
        $end = strpos($source, '  // System fallback');
        $this->assertTrue($start !== false && $end !== false && $end > $start);
        $this->assertSame(1, preg_match('/^  writeLog\(\'rtorrent exited .*$/m', $source, $log));
        // Run only the production launch/status/log fragment, never the executor's cleanup or restart loop.
        return 'namespace PMSS\\ExecutorExitFixture; '.$shims."\n"
            .'function writeLog($message) { $GLOBALS["log"] = $message; }'."\n"
            .'$rtorrentCommand = '.var_export($command, true).';'."\n"
            .substr($source, $start, $end - $start)."\n".$log[0]."\n"
            .'echo json_encode([$rtorrentRc, $rtorrentSignal, $GLOBALS["log"]]);';
    }

    public function testOrdinaryExitsNeverInferSignalsFromHighCodes(): void
    {
        foreach ([0, 1, 134, 137, 255] as $rc) {
            $result = $this->pmssRunInlinePhpJson($this->script('/bin/sh -c '.escapeshellarg('exit '.$rc)));
            $this->assertSame([$rc, 0, 'rtorrent exited (rc='.$rc
                .($rc !== 0 ? '; signal=0' : '').'); sleeping 5 seconds then restarting'], $result);
        }
    }

    public function testSignalDeathsKeepTheirOwnSignalNumber(): void
    {
        foreach ([6, 9, 11, 15] as $signal) {
            // Only the private child signals itself; suppress core files for ABRT/SEGV.
            $command = '/bin/sh -c '.escapeshellarg('ulimit -c 0; kill -'.$signal.' $$');
            $result = $this->pmssRunInlinePhpJson($this->script($command));
            $this->assertSame([128 + $signal, $signal, 'rtorrent exited (rc='.(128 + $signal)
                .'; signal='.$signal.'); sleeping 5 seconds then restarting'], $result);
        }
    }

    public function testQuotedExecutableAndArgumentsKeepTheirBytes(): void
    {
        $root = $this->pmssMakeTempDir('pmss-executor-exit-');
        $binary = $root."/fixture ' rtorrent";
        $this->pmssWriteExecutableFile($binary, "#!/bin/sh\n[ \"\$1\" = '-O' ] && [ \"\$2\" = 'space ; literal' ]\n");
        $result = $this->pmssRunInlinePhpJson($this->script(escapeshellarg($binary)
            .' -O '.escapeshellarg('space ; literal')));
        $this->assertSame([0, 0, 'rtorrent exited (rc=0); sleeping 5 seconds then restarting'], $result);
    }

    public function testChildOutputRemainsOnInheritedDescriptors(): void
    {
        $command = '/bin/sh -c '.escapeshellarg('printf "fixture-out\n"; printf "fixture-err\n" >&2; exit 7');
        $result = $this->pmssExecInlinePhp($this->script($command));
        $this->assertSame(0, $result['rc']);
        $this->assertSame(['fixture-out', 'fixture-err', json_encode([7, 0,
            'rtorrent exited (rc=7; signal=0); sleeping 5 seconds then restarting'])], $result['lines']);
    }

    public function testLaunchAndObservationFailuresRetainUnknownSignal(): void
    {
        foreach (['launch', 'status'] as $mode) {
            $shims = 'function proc_open($command, $descriptors, &$pipes) { return '
                .($mode === 'launch' ? 'false' : '\\fopen("php://memory", "w+")').'; }'
                .'function proc_get_status($process) { return false; }'
                .'function proc_close($process) { \\fclose($process); return -1; }';
            $result = $this->pmssRunInlinePhpJson($this->script('fixture-command', $shims));
            $this->assertSame([-1, null,
                'rtorrent exited (rc=-1; signal=unknown); sleeping 5 seconds then restarting'], $result);
        }
    }

    public function testFirstTerminalStatusSurvivesLegacyCloseResult(): void
    {
        $shims = <<<'PHP'
$GLOBALS['polls'] = 0;
function proc_open($command, $descriptors, &$pipes) {
    if ($command !== 'exec fixture-command' || $descriptors !== []) throw new \RuntimeException('launch changed');
    return \fopen('php://memory', 'w+');
}
function proc_get_status($process) {
    if (++$GLOBALS['polls'] > 2) throw new \RuntimeException('terminal status consumed twice');
    return ['running' => $GLOBALS['polls'] === 1, 'signaled' => false, 'exitcode' => 255];
}
function usleep($delay) { if ($delay !== 100000) throw new \RuntimeException('poll cadence changed'); }
function proc_close($process) { \fclose($process); return -1; }
PHP;
        $result = $this->pmssRunInlinePhpJson($this->script('fixture-command', $shims));
        $this->assertSame([255, 0,
            'rtorrent exited (rc=255; signal=0); sleeping 5 seconds then restarting'], $result);
    }
}
