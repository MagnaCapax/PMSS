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
    private $tempDir;

    public function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-test-'.getmypid();
        @mkdir($this->tempDir, 0755, true);
    }

    public function tearDown(): void
    {
        // Cleanup temp files.
        $files = glob($this->tempDir.'/*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
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

    /**
     * Test pgrep exact returns empty for nonexistent user.
     */
    public function testPgrepExactNonexistentUser(): void
    {
        $pids = rtorrentProcessPgrepExact('nonexistent_user_12345', 'rtorrent');
        $this->assertEquals([], $pids);
    }

    /**
     * Test executor PIDs returns empty for nonexistent user.
     */
    public function testExecutorPidsNonexistentUser(): void
    {
        $result = rtorrentProcessExecutorPids('nonexistent_user_12345');
        $this->assertEquals(['php' => [], 'screen' => [], 'all' => []], $result);
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
}
