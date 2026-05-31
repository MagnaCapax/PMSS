<?php
/**
 * Tests for rTorrent process management helpers.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 */

namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/rtorrent/process.php';

class RtorrentProcessTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-rtorrent-process-');
    }

    /**
     * Test stale state: first detection records timestamp.
     */
    public function testStaleStateRecordsFirstSeen(): void
    {
        $stateFile = $this->tempDir.'/test-state.ts';

        $result = rtorrentProcessCheckStaleState($stateFile, 60);

        $this->assertEquals('record', $result['action']);
        $this->assertEquals(0, $result['age']);
        $this->assertTrue(file_exists($stateFile), 'State file should be created');
    }

    /**
     * Test stale state: within grace period returns wait.
     */
    public function testStaleStateWaitsWithinGrace(): void
    {
        $stateFile = $this->tempDir.'/test-state.ts';

        // Record initial state.
        file_put_contents($stateFile, (string)(time() - 30));

        $result = rtorrentProcessCheckStaleState($stateFile, 60);

        $this->assertEquals('wait', $result['action']);
        $this->assertTrue($result['age'] >= 29 && $result['age'] <= 32);
    }

    /**
     * Test stale state: exceeding grace period returns stale.
     */
    public function testStaleStateExceedsGrace(): void
    {
        $stateFile = $this->tempDir.'/test-state.ts';

        // Record state from 120 seconds ago.
        file_put_contents($stateFile, (string)(time() - 120));

        $result = rtorrentProcessCheckStaleState($stateFile, 60);

        $this->assertEquals('stale', $result['action']);
        $this->assertTrue($result['age'] >= 119 && $result['age'] <= 122);
    }

    /**
     * Test clear stale state removes file.
     */
    public function testClearStaleStateRemovesFile(): void
    {
        $stateFile = $this->tempDir.'/test-state.ts';
        file_put_contents($stateFile, (string)time());

        $this->assertTrue(file_exists($stateFile));

        rtorrentProcessClearStaleState($stateFile);

        $this->assertTrue(!file_exists($stateFile), 'State file should be removed');
    }

    /**
     * Test clear stale state handles missing file gracefully.
     */
    public function testClearStaleStateMissingFileNoError(): void
    {
        $stateFile = $this->tempDir.'/nonexistent.ts';

        // Should not throw.
        rtorrentProcessClearStaleState($stateFile);

        $this->assertTrue(true, 'No exception thrown');
    }

    /**
     * Test stale state handles invalid timestamp.
     */
    public function testStaleStateInvalidTimestamp(): void
    {
        $stateFile = $this->tempDir.'/test-state.ts';
        file_put_contents($stateFile, 'invalid');

        $result = rtorrentProcessCheckStaleState($stateFile, 60);

        // Invalid timestamp should be treated as first detection.
        $this->assertEquals('record', $result['action']);
    }

    /**
     * Test stale state handles empty file.
     */
    public function testStaleStateEmptyFile(): void
    {
        $stateFile = $this->tempDir.'/test-state.ts';
        file_put_contents($stateFile, '');

        $result = rtorrentProcessCheckStaleState($stateFile, 60);

        $this->assertEquals('record', $result['action']);
    }

    /**
     * Test stale state handles zero timestamp.
     */
    public function testStaleStateZeroTimestamp(): void
    {
        $stateFile = $this->tempDir.'/test-state.ts';
        file_put_contents($stateFile, '0');

        $result = rtorrentProcessCheckStaleState($stateFile, 60);

        $this->assertEquals('record', $result['action']);
    }

    /**
     * Test failed-start state: first failure records attempt 1.
     */
    public function testFailureCountStateRecordsFirstAttempt(): void
    {
        $stateFile = $this->tempDir.'/test-failure.count';

        $result = rtorrentProcessCheckFailureCountState($stateFile, 4);

        $this->assertEquals('record', $result['action']);
        $this->assertEquals(1, $result['count']);
        $this->assertEquals('1', trim((string) file_get_contents($stateFile)));
    }

    /**
     * Test failed-start state: intermediate failures keep waiting.
     */
    public function testFailureCountStateWaitsBeforeThreshold(): void
    {
        $stateFile = $this->tempDir.'/test-failure.count';
        file_put_contents($stateFile, '2');

        $result = rtorrentProcessCheckFailureCountState($stateFile, 4);

        $this->assertEquals('wait', $result['action']);
        $this->assertEquals(3, $result['count']);
    }

    /**
     * Test failed-start state: reaching threshold becomes stale.
     */
    public function testFailureCountStateBecomesStaleAtThreshold(): void
    {
        $stateFile = $this->tempDir.'/test-failure.count';
        file_put_contents($stateFile, '3');

        $result = rtorrentProcessCheckFailureCountState($stateFile, 4);

        $this->assertEquals('stale', $result['action']);
        $this->assertEquals(4, $result['count']);
    }

    /**
     * Test failed-start state: invalid file contents reset to attempt 1.
     */
    public function testFailureCountStateInvalidContentsResetCount(): void
    {
        $stateFile = $this->tempDir.'/test-failure.count';
        file_put_contents($stateFile, 'invalid');

        $result = rtorrentProcessCheckFailureCountState($stateFile, 4);

        $this->assertEquals('record', $result['action']);
        $this->assertEquals(1, $result['count']);
    }

    /**
     * Test failed-start state: negative counts reset to attempt 1.
     */
    public function testFailureCountStateNegativeContentsResetCount(): void
    {
        $stateFile = $this->tempDir.'/test-failure.count';
        file_put_contents($stateFile, '-3');

        $result = rtorrentProcessCheckFailureCountState($stateFile, 4);

        $this->assertEquals('record', $result['action']);
        $this->assertEquals(1, $result['count']);
    }

    /**
     * Test failed-start state: thresholds below 1 escalate immediately.
     */
    public function testFailureCountStateNormalizesZeroThreshold(): void
    {
        $stateFile = $this->tempDir.'/test-failure.count';

        $result = rtorrentProcessCheckFailureCountState($stateFile, 0);

        $this->assertEquals('stale', $result['action']);
        $this->assertEquals(1, $result['count']);
    }

    public function testStateFilePathSafetyAcceptsNormalTempFile(): void
    {
        $this->assertTrue(rtorrentProcessStateFilePathIsSafe($this->tempDir.'/state.ts'));
    }

    public function testStateFilePathSafetyRejectsRelativeAndTraversalPaths(): void
    {
        $this->assertFalse(rtorrentProcessStateFilePathIsSafe('state.ts'));
        $this->assertFalse(rtorrentProcessStateFilePathIsSafe($this->tempDir.'/../state.ts'));
        $this->assertFalse(rtorrentProcessStateFilePathIsSafe($this->tempDir."/state\0.ts"));
    }

    public function testStaleStateRefusesSymlinkParentWithoutWriting(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }

        $targetDir = $this->tempDir.'/target';
        $linkDir = $this->tempDir.'/link';
        @mkdir($targetDir, 0755, true);
        if (@symlink($targetDir, $linkDir) === false) {
            throw new SkipTest('symlink() failed');
        }

        $result = rtorrentProcessCheckStaleState($linkDir.'/state.ts', 60);

        $this->assertEquals('record', $result['action']);
        $this->assertFalse(file_exists($targetDir.'/state.ts'), 'State marker must not be written through symlink parent');
    }

    public function testFailureCountStateRefusesDirectoryTarget(): void
    {
        $stateFile = $this->tempDir.'/state.count';
        @mkdir($stateFile, 0755, true);

        $result = rtorrentProcessCheckFailureCountState($stateFile, 4);

        $this->assertEquals('record', $result['action']);
        $this->assertEquals(1, $result['count']);
        $this->assertTrue(is_dir($stateFile), 'Directory target should remain untouched');
    }

    public function testClearStaleStateRefusesSymlinkLeaf(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }

        $target = $this->tempDir.'/target-state.ts';
        $link = $this->tempDir.'/linked-state.ts';
        file_put_contents($target, '123');
        if (@symlink($target, $link) === false) {
            throw new SkipTest('symlink() failed');
        }

        rtorrentProcessClearStaleState($link);

        $this->assertTrue(is_link($link), 'Symlink marker should remain untouched');
        $this->assertTrue(is_file($target), 'Symlink target should remain untouched');
    }

    /**
     * Test executor PIDs returns empty for nonexistent user.
     */
    public function testExecutorPidsNonexistentUser(): void
    {
        $result = rtorrentProcessExecutorPids('nonexistent_user_12345');
        $this->assertEquals(['php' => [], 'screen' => [], 'all' => []], $result);
    }

    public function testProcessLookupsExposeCommandDetailsByReference(): void
    {
        $rc = null;
        $output = null;

        $this->assertEquals([], pmssUserWatchdogProcessPids('nonexistent_user_12345', '^rtorrent', [], $rc, $output));
        $this->assertTrue(is_int($rc), 'Expected integer exit code reference');
        $this->assertTrue(is_array($output), 'Expected raw output reference array');

        $result = rtorrentProcessExecutorPids('nonexistent_user_12345', $rc, $output);
        $this->assertEquals(['php' => [], 'screen' => [], 'all' => []], $result);
        $this->assertTrue(is_int($rc), 'Expected integer exit code reference');
        $this->assertTrue(is_array($output), 'Expected raw output reference array');
    }

    /**
     * Test kill PIDs handles empty array.
     */
    public function testKillPidsEmptyArray(): void
    {
        // Should not throw.
        rtorrentProcessKillPids([], SIGTERM);
        $this->assertTrue(true, 'No exception thrown');
    }

    /**
     * Test kill PIDs handles invalid PIDs.
     */
    public function testKillPidsInvalidPids(): void
    {
        // Should not throw.
        rtorrentProcessKillPids([0, -1, -999], SIGTERM);
        $this->assertTrue(true, 'No exception thrown');
    }

    /**
     * Test process snapshot returns array for nonexistent user.
     */
    public function testProcessSnapshotNonexistentUser(): void
    {
        $result = rtorrentProcessSnapshot('nonexistent_user_12345');
        $this->assertTrue(is_array($result));
    }

    public function testProcessStateParserCapturesPidStatAndWchan(): void
    {
        $state = rtorrentProcessStateFromPsLine('1234 Sl+ futex_wait_queue');

        $this->assertSame(['pid' => 1234, 'stat' => 'Sl+', 'wchan' => 'futex_wait_queue'], $state);
    }

    public function testProcessStateParserAllowsMissingWchan(): void
    {
        $state = rtorrentProcessStateFromPsLine('1234 Sl+');

        $this->assertSame(['pid' => 1234, 'stat' => 'Sl+', 'wchan' => ''], $state);
    }

    public function testProcessStateParserRejectsMalformedRows(): void
    {
        $this->assertSame(null, rtorrentProcessStateFromPsLine(''));
        $this->assertSame(null, rtorrentProcessStateFromPsLine('pid Sl+ futex_wait_queue'));
    }

    public function testProcessStatesFromPsLinesArePidKeyed(): void
    {
        $states = rtorrentProcessStatesFromPsLines([
            '1234 Sl+ futex_wait_queue',
            '2345 Dl+ wait_transaction_locked',
        ]);

        $this->assertSame('Sl+', $states[1234]['stat']);
        $this->assertSame('wait_transaction_locked', $states[2345]['wchan']);
    }

    public function testProcessStatesHaveUninterruptibleIoDetectsDState(): void
    {
        $safe = rtorrentProcessStatesFromPsLines(['1234 Sl+ futex_wait_queue']);
        $unsafe = rtorrentProcessStatesFromPsLines(['2345 Dl+ wait_transaction_locked']);

        $this->assertFalse(rtorrentProcessStatesHaveUninterruptibleIo($safe));
        $this->assertTrue(rtorrentProcessStatesHaveUninterruptibleIo($unsafe));
    }

    /**
     * Test signal constants are defined.
     */
    public function testSignalConstantsDefined(): void
    {
        $this->assertTrue(defined('SIGTERM'));
        $this->assertTrue(defined('SIGKILL'));
        $this->assertEquals(15, SIGTERM);
        $this->assertEquals(9, SIGKILL);
    }

    public function testProcessStartOwnsLaunchCommandAndRestartMarkers(): void
    {
        $processSource = $this->pmssReadRepoFile('scripts/lib/rtorrent/process.php');
        $watchdogSource = $this->pmssReadRepoFile('scripts/cron/checkRtorrent.php');

        $this->assertSame(1, substr_count($processSource, "@passthru('/scripts/startRtorrent "));
        $this->assertStringContainsAllStrings([
            'function rtorrentProcessStart(',
            "'/tmp/.pmss-rtorrent-restart-'.\$user",
            'rtorrentProcessWriteStateFile($startMarkerState, $now)',
            '$rc = rtorrentProcessStart($user, $logFn);',
        ], $processSource);
        $this->assertStringContainsAllStrings([
            'rtorrentProcessStart($user, $logCallback, $startMarkerState)',
            'rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);',
        ], $watchdogSource);
        $this->pmssAssertStringNotContainsString("@passthru('/scripts/startRtorrent ", $watchdogSource);
    }

    public function testResetSessionDirectoryQuarantinesAndRecreatesDirectory(): void
    {
        $home = $this->tempDir.'/alice';
        $sessionDir = $home.'/session';
        @mkdir($sessionDir, 0755, true);
        file_put_contents($sessionDir.'/resume.dat', 'state');
        [$result, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($home): bool {
            return rtorrentProcessResetSessionDirectory($home, 'alice', $logger);
        });

        $this->assertTrue($result, 'Session reset should succeed for a normal directory');
        $this->assertTrue(is_dir($sessionDir), 'Session directory should exist after reset');
        $this->assertTrue(!file_exists($sessionDir.'/resume.dat'), 'Old session files should not remain in the new directory');
        $this->assertTrue(count(glob($home.'/session.broken-*')) === 1, 'Original directory should be quarantined once');
        $this->assertTrue(strpos(implode("\n", $messages), 'Quarantined broken session directory') !== false);
    }

    public function testResetSessionDirectoryCreatesMissingDirectory(): void
    {
        $home = $this->tempDir.'/alice';
        @mkdir($home, 0755, true);

        $result = rtorrentProcessResetSessionDirectory($home, 'alice', function (): void {
        });

        $this->assertTrue($result, 'Missing session directory should be created');
        $this->assertTrue(is_dir($home.'/session'));
    }

    public function testResetSessionDirectoryRejectsUnexpectedPath(): void
    {
        [$result, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger): bool {
            return rtorrentProcessResetSessionDirectory($this->tempDir.'/alice', 'bob', $logger);
        });

        $this->assertTrue(!$result, 'Unexpected home path should be rejected');
        $this->assertTrue(strpos(implode("\n", $messages), 'Refusing to reset unexpected session directory') !== false);
    }

    public function testResetSessionDirectoryRejectsSymlink(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }

        $home = $this->tempDir.'/alice';
        @mkdir($home, 0755, true);
        @mkdir($this->tempDir.'/target', 0755, true);
        @symlink($this->tempDir.'/target', $home.'/session');
        [$result, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($home): bool {
            return rtorrentProcessResetSessionDirectory($home, 'alice', $logger);
        });

        $this->assertTrue(!$result, 'Symlinked session directory should be rejected');
        $this->assertTrue(strpos(implode("\n", $messages), 'Refusing to reset symlinked session directory') !== false);
    }

    public function testResetSessionDirectoryRejectsSymlinkedHome(): void
    {
        if (!function_exists('symlink')) {
            throw new SkipTest('symlink unavailable');
        }

        $home = $this->tempDir.'/alice';
        $targetHome = $this->tempDir.'/target-home';
        @mkdir($targetHome.'/session', 0755, true);
        file_put_contents($targetHome.'/session/resume.dat', 'state');
        if (@symlink($targetHome, $home) === false) {
            throw new SkipTest('symlink() failed');
        }

        [$result, $messages] = $this->pmssArrayLoggerCapture(function (callable $logger) use ($home): bool {
            return rtorrentProcessResetSessionDirectory($home, 'alice', $logger);
        });

        $this->assertTrue(!$result, 'Symlinked user home should be rejected');
        $this->assertTrue(is_link($home), 'Home symlink should remain untouched');
        $this->assertTrue(is_file($targetHome.'/session/resume.dat'), 'Session data behind symlinked home should remain untouched');
        $this->assertTrue(strpos(implode("\n", $messages), 'Refusing to reset unsafe session directory') !== false);
    }

    public function testResetSessionDirectoryCanRunTwice(): void
    {
        $home = $this->tempDir.'/alice';
        $sessionDir = $home.'/session';
        @mkdir($sessionDir, 0755, true);

        $first = rtorrentProcessResetSessionDirectory($home, 'alice', function (): void {
        });
        $second = rtorrentProcessResetSessionDirectory($home, 'alice', function (): void {
        });

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertTrue(is_dir($sessionDir));
        $this->assertTrue(count(glob($home.'/session.broken-*')) >= 1, 'At least one quarantine directory should exist');
    }
}
