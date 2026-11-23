<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';
require_once dirname(__DIR__, 2).'/user/toggleApp.php';

/** A panel-started toggle app keeps its console output in a bounded, account-private log. */
class ToggleAppConsoleLogTest extends TestCase
{
    public function testConsoleOutputOfBothStreamsReachesAPrivateLog(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-toggle-log-');
        $log = $dir.'/scryer.log';
        $launch = 'echo warn-on-stdout; echo warn-on-stderr >&2';
        // The shell's own umask is loose; the group sets 0600 before the app writes.
        exec('umask 022; bash -c '.escapeshellarg(\pmssToggleAppLoggedLaunch($launch, $log)));
        clearstatcache();
        $text = (string) file_get_contents($log);
        $this->assertStringContainsString("warn-on-stdout\n", $text);
        $this->assertStringContainsString("warn-on-stderr\n", $text);

        // Through the detached group, as the watchdog runs it: the group returns at once and `wait` joins it.
        $group = \pmssToggleAppDetachedGroup('true', \pmssToggleAppLoggedLaunch($launch, $dir.'/qui.log'));
        exec('umask 022; bash -c '.escapeshellarg($group.' wait'));
        clearstatcache();
        $this->assertSame(0600, fileperms($dir.'/qui.log') & 0777);
        $this->assertStringContainsString("warn-on-stderr\n", (string) file_get_contents($dir.'/qui.log'));
    }

    public function testLogStaysBoundedAndKeepsTheNewestOutput(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-toggle-log-cap-');
        $log = $dir.'/slskd.log';
        // 20 000 bytes against a 1 600 byte cap, in lines the reader can order.
        $launch = 'i=0; while [ $i -lt 2000 ]; do printf "line%05d\\n" $i; i=$((i+1)); done';
        exec('bash -c '.escapeshellarg(\pmssToggleAppLoggedLaunch($launch, $log, 1600)));
        clearstatcache();
        $this->assertTrue(filesize($log) > 0 && filesize($log) <= 1600 + 100, 'log stays within the cap plus one chunk and is never emptied');
        $this->assertSame([$log], glob($dir.'/*'), 'no temporary file is left behind');
        $this->assertSame(1, preg_match('/line01999\n\z/', (string) file_get_contents($log)), 'newest line is the last one kept');
        $this->assertSame(0, preg_match('/line00000/', (string) file_get_contents($log)), 'oldest output was dropped');
    }

    public function testAppKeepsRunningWhileTheLogRotatesAndNeverMeetsABrokenPipe(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-toggle-log-pipe-');
        $marker = $dir.'/finished';
        // The launch outlives several trims and writes after the reader's first chunk; it must finish its work.
        $launch = 'i=0; while [ $i -lt 300 ]; do printf "0123456789abcdef\\n"; i=$((i+1)); done; touch '.escapeshellarg($marker);
        exec('bash -c '.escapeshellarg(\pmssToggleAppLoggedLaunch($launch, $dir.'/app.log', 800)));
        $this->assertTrue(is_file($marker));
    }

    public function testUnwritableLogNeverBreaksThePipeUnderTheApp(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-toggle-log-unwritable-');
        $marker = $dir.'/finished';
        // The log directory does not exist, as when a full quota refuses every write.
        $launch = 'i=0; while [ $i -lt 300 ]; do printf "0123456789abcdef\\n"; i=$((i+1)); done; touch '.escapeshellarg($marker);
        exec('bash -c '.escapeshellarg(\pmssToggleAppLoggedLaunch($launch, $dir.'/missing/app.log')).' 2>/dev/null');
        $this->assertTrue(is_file($marker), 'the app finished its writes without a broken pipe');
    }
    public function testStartCommandWiresTheCatalogLogPathAndNoLongerDiscardsOutput(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/toggleApp.php', [
            "dirname(\$row['securedMarker']).'/'.\$app.'.log'",
            'pmssToggleAppLoggedLaunch(pmssToggleAppLaunchCommand(',
        ]);
        $log = '/home/fixtur01/.pmss/apps/scryer.log';
        $command = \pmssToggleAppDetachedGroup('true', \pmssToggleAppLoggedLaunch('echo hi', $log));
        // The app's own streams end in the log; only the detached group's streams go to the void.
        $this->assertStringContainsString(">> '".$log."'", $command);
        $this->assertSame(0, strpos($command, '( umask 077; true && { echo hi; } 2>&1 | {'));
        $this->assertSame(' </dev/null >/dev/null 2>&1 &', substr($command, -strlen(' </dev/null >/dev/null 2>&1 &')));
    }
}
