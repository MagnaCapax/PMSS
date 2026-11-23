<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * End-to-end journeys of the hourly access-log trim cron (retention cap, ADR 0337).
 *
 * Each test runs scripts/cron/lighttpdAccessLogTrim.php itself in a child process, as root cron does. The source is
 * patched at four seams only (home base, user list, size threshold, log directory) so it touches fixtures, never /home.
 */
class LighttpdAccessLogCronJourneyTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-access-log-cron-']; }

    /** One default-format access-log line stamped $age seconds ago. */
    private function pmssLine(int $age, string $user = 'fixtur01'): string
    {
        return '203.0.113.7 - '.$user.' ['.gmdate('d/M/Y:H:i:s', time() - $age).' +0000] "GET /public-'.$user.'/ HTTP/1.1" 200 512 "-" "agent"'."\n";
    }

    private function pmssLogPath(string $user): string { return $this->tempDir.'/home/'.$user.'/.lighttpd/access.log'; }

    /** @return array{rc:int,output:string} */
    private function pmssRunCron(array $users, int $threshold = 104857600): array
    {
        $script = <<<'PHP'
$fx = getenv('PMSS_TEST_FX');
$path = getenv('PMSS_TEST_CRON');
@mkdir($fx.'/log', 0700, true);
define('PMSS_LIGHTTPD_ACCESS_LOG_THRESHOLD_BYTES', (int) getenv('PMSS_TEST_THRESHOLD'));
$code = (string) file_get_contents($path);
$code = substr($code, strpos($code, '<?php') + 5);
$code = str_replace(
    ['new Logger(__FILE__)', '__DIR__', '/home/{$thisUser}/', 'pmssManagedHomeUsersList()'],
    ["new Logger('lighttpdAccessLogTrim.php', '".$fx."/log', '".$fx."/log')", var_export(dirname($path), true), $fx.'/home/{$thisUser}/', "explode(',', getenv('PMSS_TEST_USERS'))"],
    $code
);
eval($code);
PHP;
        $run = $this->pmssExecInlinePhp($script, [
            'PMSS_TEST_FX' => $this->tempDir,
            'PMSS_TEST_CRON' => $this->pmssRepoPath('scripts/cron/lighttpdAccessLogTrim.php'),
            'PMSS_TEST_THRESHOLD' => (string) $threshold,
            'PMSS_TEST_USERS' => implode(',', $users),
        ]);
        return ['rc' => $run['rc'], 'output' => $run['output']];
    }

    private function pmssSize(string $path): int { clearstatcache(); return (int) filesize($path); }

    public function testUc01AgedLogStillReceivingWritesIsClearedByTheCron(): void
    {
        // The issue's gap: the log is appended to (fresh mtime, fresh last line) yet visitor data from 45 days ago is still there.
        $this->pmssWriteFile($this->pmssLogPath('fixtur01'), $this->pmssLine(45 * 86400).$this->pmssLine(60));
        $run = $this->pmssRunCron(['fixtur01']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertTrue(strpos($run['output'], 'Trimmed aged lighttpd access log for fixtur01') !== false, $run['output']);
        $this->assertSame(0, $this->pmssSize($this->pmssLogPath('fixtur01')));
    }

    public function testUc02LogWithinTheRetentionWindowIsLeftAlone(): void
    {
        $content = $this->pmssLine(2 * 86400).$this->pmssLine(60);
        $this->pmssWriteFile($this->pmssLogPath('fixtur01'), $content);
        $run = $this->pmssRunCron(['fixtur01']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertFalse(strpos($run['output'], 'Trimmed') !== false, $run['output']);
        $this->assertSame($content, file_get_contents($this->pmssLogPath('fixtur01')));
    }

    public function testUc03OversizedLogIsStillTrimmedBySizeNotReportedAsAged(): void
    {
        // The existing size safety net keeps its behaviour and its message.
        $this->pmssWriteFile($this->pmssLogPath('fixtur01'), str_repeat($this->pmssLine(60), 40));
        $run = $this->pmssRunCron(['fixtur01'], 1024);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertTrue(strpos($run['output'], 'Trimmed oversized lighttpd access log for fixtur01') !== false, $run['output']);
        $this->assertFalse(strpos($run['output'], 'aged') !== false, $run['output']);
        $this->assertSame(0, $this->pmssSize($this->pmssLogPath('fixtur01')));
    }

    public function testUc04RerunAfterATrimKeepsEntriesWrittenSince(): void
    {
        $path = $this->pmssLogPath('fixtur01');
        $this->pmssWriteFile($path, $this->pmssLine(45 * 86400));
        $this->assertSame(0, $this->pmssRunCron(['fixtur01'])['rc']);
        $this->assertSame(0, $this->pmssSize($path));
        // The pipe logger appends again after the trim; the next hourly run must not clear it.
        $fresh = $this->pmssLine(30);
        $this->assertTrue(file_put_contents($path, $fresh, FILE_APPEND) !== false);
        $run = $this->pmssRunCron(['fixtur01']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertFalse(strpos($run['output'], 'Trimmed') !== false, $run['output']);
        $this->assertSame($fresh, file_get_contents($path));
    }

    public function testUc05AccountWithoutAnAccessLogRunsCleanly(): void
    {
        // A new account: lighttpd directory exists, no log yet.
        $this->assertTrue(mkdir($this->tempDir.'/home/fixtur01/.lighttpd', 0755, true));
        $run = $this->pmssRunCron(['fixtur01']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertFalse(strpos($run['output'], 'Trimmed') !== false || strpos($run['output'], 'WARN') !== false, $run['output']);
        $this->assertFalse(file_exists($this->pmssLogPath('fixtur01')));
    }

    public function testUc06EmptyLogWithAnAncientMtimeIsNotTouched(): void
    {
        $path = $this->pmssLogPath('fixtur01');
        $this->pmssWriteFile($path, '');
        touch($path, time() - 400 * 86400);
        $run = $this->pmssRunCron(['fixtur01']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertFalse(strpos($run['output'], 'Trimmed') !== false, $run['output']);
        $this->assertSame(0, $this->pmssSize($path));
    }

    public function testUc07SymlinkedLogIsSkippedAndItsTargetKept(): void
    {
        // A user-managed aggregation setup: the old log behind the link must never be truncated through it.
        $target = $this->pmssWriteFile($this->tempDir.'/aggregated.log', $this->pmssLine(90 * 86400));
        $this->pmssWriteSymlinks($this->tempDir, ['home/fixtur01/.lighttpd/access.log' => $target]);
        $before = file_get_contents($target);
        $run = $this->pmssRunCron(['fixtur01']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertFalse(strpos($run['output'], 'Trimmed') !== false, $run['output']);
        $this->assertSame($before, file_get_contents($target));
    }

    public function testUc08OnlyTheAccountWithAnAgedLogIsTrimmed(): void
    {
        // Another account's boundary: one run over three accounts clears only the aged ones.
        $young = $this->pmssLine(86400, 'fixtur02').$this->pmssLine(30, 'fixtur02');
        $this->pmssWriteFile($this->pmssLogPath('fixtur01'), $this->pmssLine(50 * 86400, 'fixtur01'));
        $this->pmssWriteFile($this->pmssLogPath('fixtur02'), $young);
        $this->pmssWriteFile($this->pmssLogPath('fixtur03'), $this->pmssLine(31 * 86400, 'fixtur03'));
        $run = $this->pmssRunCron(['fixtur01', 'fixtur02', 'fixtur03']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertSame(0, $this->pmssSize($this->pmssLogPath('fixtur01')));
        $this->assertSame($young, file_get_contents($this->pmssLogPath('fixtur02')));
        $this->assertSame(0, $this->pmssSize($this->pmssLogPath('fixtur03')));
        $this->assertFalse(strpos($run['output'], 'fixtur02') !== false, $run['output']);
    }

    public function testUc09ExistingLogsWithoutATimestampFollowTheirModificationTime(): void
    {
        // Upgrade path: logs written before the cap exist in any format; one without a stamp falls back to the mtime.
        $stale = $this->pmssLogPath('fixtur01');
        $this->pmssWriteFile($stale, "custom-format entry without a bracketed stamp\n");
        touch($stale, time() - 60 * 86400);
        $recent = $this->pmssLogPath('fixtur02');
        $this->pmssWriteFile($recent, "custom-format entry without a bracketed stamp\n");
        $run = $this->pmssRunCron(['fixtur01', 'fixtur02']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertSame(0, $this->pmssSize($stale));
        $this->assertTrue($this->pmssSize($recent) > 0);
    }

    public function testUc10EntryJustInsideAndJustOutsideThirtyDays(): void
    {
        $inside = $this->pmssLine(30 * 86400 - 3600, 'fixtur01');
        $this->pmssWriteFile($this->pmssLogPath('fixtur01'), $inside);
        $this->pmssWriteFile($this->pmssLogPath('fixtur02'), $this->pmssLine(30 * 86400 + 3600, 'fixtur02'));
        $run = $this->pmssRunCron(['fixtur01', 'fixtur02']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertSame($inside, file_get_contents($this->pmssLogPath('fixtur01')));
        $this->assertSame(0, $this->pmssSize($this->pmssLogPath('fixtur02')));
    }

    public function testUc11MultiplyLinkedLogIsSkippedEvenWhenAged(): void
    {
        $path = $this->pmssLogPath('fixtur01');
        $content = $this->pmssLine(90 * 86400);
        $this->pmssWriteFile($path, $content);
        $this->assertTrue(link($path, $this->tempDir.'/second-name.log'));
        $run = $this->pmssRunCron(['fixtur01']);
        $this->assertSame(0, $run['rc'], $run['output']);
        $this->assertFalse(strpos($run['output'], 'Trimmed') !== false, $run['output']);
        $this->assertSame($content, file_get_contents($path));
        $this->assertSame($content, file_get_contents($this->tempDir.'/second-name.log'));
    }
}
