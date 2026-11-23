<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/systemStats.php';

/** The host-stats collector must stay hermetic: PMSS_TEST_MODE never starts a real disk-latency probe. */
class SystemStatsIopingTestModeTest extends TestCase
{
    /** Build a PATH holding an ioping stub that records every launch, plus a pass-through flock. */
    private function pmssIopingLaunchStubDir(string $launchLog): string
    {
        $binDir = $this->pmssMakeTempDir('pmss-stats-ioping-');
        $this->pmssWriteExecutableFiles($binDir, [
            'ioping' => "#!/bin/sh\nprintf 'launched\\n' >> ".escapeshellarg($launchLog)
                ."\nprintf '%s\\n' '4 KiB <<< /tmp: request=1 time=2.0 ms'\n",
            'flock' => "#!/bin/sh\nshift 4\nexec \"\$@\"\n",
        ]);
        return $binDir;
    }

    public function testStubbedProbeIsLaunchedOutsideTheStatsGate(): void
    {
        // Control: the stub is reachable and the canonical probe does launch it, so the
        // absence checks below prove the gate and not a broken fixture.
        $launchLog = $this->pmssMakeTempFile('pmss-stats-ioping-log-');
        $binDir = $this->pmssIopingLaunchStubDir($launchLog);
        $this->pmssWithPathPrefix($binDir, function () use ($launchLog): void {
            $this->assertEquals(2.0, \pmssIopingMedianMs('/tmp', true));
            $this->assertSame("launched\n", (string) file_get_contents($launchLog));
        });
    }

    public function testStatsProbeReportsNaInTestModeWithoutLaunchingIoping(): void
    {
        $this->assertTrue(\pmssTestModeEnabled(), 'The development runner must run in PMSS_TEST_MODE');
        $launchLog = $this->pmssMakeTempFile('pmss-stats-ioping-log-');
        $binDir = $this->pmssIopingLaunchStubDir($launchLog);
        $this->pmssWithPathPrefix($binDir, function () use ($launchLog): void {
            foreach (['/', '/home', '/tmp', '/nonexistent-directory'] as $path) {
                $this->assertSame('na', \pmssSystemStatsIopingMs($path), $path);
            }
            $this->assertSame('', (string) file_get_contents($launchLog), 'Test mode must not launch ioping');
        });
    }

    public function testCollectorInTestModeNeverStartsRealDiskProbesAndStaysFast(): void
    {
        $launchLog = $this->pmssMakeTempFile('pmss-stats-ioping-log-');
        $binDir = $this->pmssIopingLaunchStubDir($launchLog);
        $this->pmssWithPathPrefix($binDir, function () use ($launchLog): void {
            $start = microtime(true);
            $stats = \pmssSystemStatsCollect();
            $elapsed = microtime(true) - $start;
            // The stub makes ioping "present", so only the test-mode gate can keep these at na.
            $this->assertSame('na', $stats['iopingRoot']);
            $this->assertSame('na', $stats['iopingHome']);
            $this->assertSame('', (string) file_get_contents($launchLog), 'Collector launched ioping in test mode');
            $this->assertTrue($elapsed < 0.9, 'Collector must not wait on disk probes in test mode, got '.$elapsed.'s');
        });
    }
}
