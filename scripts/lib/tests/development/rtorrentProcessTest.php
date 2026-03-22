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

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        $children = glob($path.'/*');
        if (is_array($children)) {
            foreach ($children as $child) {
                $this->removeTree($child);
            }
        }
        @rmdir($path);
    }

    public function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/pmss-test-'.getmypid();
        @mkdir($this->tempDir, 0755, true);
    }

    public function tearDown(): void
    {
        $children = glob($this->tempDir.'/*');
        if (is_array($children)) {
            foreach ($children as $child) {
                $this->removeTree($child);
            }
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

    public function testProcessLookupsExposeCommandDetailsByReference(): void
    {
        $rc = null;
        $output = null;

        $this->assertEquals([], rtorrentProcessPgrepExact('nonexistent_user_12345', 'rtorrent', $rc, $output));
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

    public function testResetSessionDirectoryQuarantinesAndRecreatesDirectory(): void
    {
        $home = $this->tempDir.'/alice';
        $sessionDir = $home.'/session';
        @mkdir($sessionDir, 0755, true);
        file_put_contents($sessionDir.'/resume.dat', 'state');
        $messages = [];

        $result = rtorrentProcessResetSessionDirectory($home, 'alice', function (string $message) use (&$messages): void {
            $messages[] = $message;
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
        $messages = [];

        $result = rtorrentProcessResetSessionDirectory($this->tempDir.'/alice', 'bob', function (string $message) use (&$messages): void {
            $messages[] = $message;
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
        $messages = [];

        $result = rtorrentProcessResetSessionDirectory($home, 'alice', function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertTrue(!$result, 'Symlinked session directory should be rejected');
        $this->assertTrue(strpos(implode("\n", $messages), 'Refusing to reset symlinked session directory') !== false);
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
