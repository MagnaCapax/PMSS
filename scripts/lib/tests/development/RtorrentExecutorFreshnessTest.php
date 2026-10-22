<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise the real executor loop with inert children and simulated freshness probes. */
final class RtorrentExecutorFreshnessTest extends TestCase
{
    private function runExecutor(string $mode): array
    {
        $home = $this->pmssMakeTempDir('pmss-executor-freshness-');
        $this->pmssWriteFile($home.'/.scriptsInc.php', '<?php');
        $source = $this->pmssReadRepoFile('etc/skel/.rtorrentExecute.php');
        $source = preg_replace('/\A#![^\n]*\n<\?php\n/', '', $source);
        // Namespaced shims replace every child/system action; no rTorrent or cleanup command runs.
        $shims = <<<'PHP'
<?php
namespace PMSS\ExecutorFixture;
$children = 0;
$hashReads = 0;
$logs = [];
$childLocks = [];
function writeLog($message) { $GLOBALS['logs'][] = $message; }
function sleep($seconds) { return 0; }
function system($command) { return ''; }
function readlink($path) {
    if (MODE === 'unreadableExe') return false;
    if (MODE === 'deletedNow' || (MODE === 'deleted' && $GLOBALS['children'] > 0)) {
        return '/usr/bin/php (deleted)';
    }
    return MODE === 'suffixOnly' ? '/usr/bin/php (deleted).new' : '/usr/bin/php';
}
function hash_file($algorithm, $path) {
    $read = ++$GLOBALS['hashReads'];
    if ((MODE === 'unreadableInitial' && $read === 1) ||
        (MODE === 'unreadableCurrent' && $read > 1)) return false;
    return \hash_file($algorithm, $path);
}
function passthru($command, &$rc) {
    $probe = fopen('.rtorrentExecute.lock', 'c');
    $GLOBALS['childLocks'][] = !flock($probe, LOCK_EX | LOCK_NB);
    fclose($probe);
    $rc = 0;
    if (++$GLOBALS['children'] === 2) throw new \RuntimeException('fixture complete');
    if (in_array(MODE, ['changed', 'unreadableInitial', 'unreadableCurrent'], true)) {
        file_put_contents(__FILE__, "\n// Updated launcher.\n", FILE_APPEND);
    }
}
if (MODE === 'locked') {
    $heldLock = fopen('.rtorrentExecute.lock', 'c');
    flock($heldLock, LOCK_EX | LOCK_NB);
}
register_shutdown_function(static function () {
    $probe = fopen('.rtorrentExecute.lock', 'c');
    $free = flock($probe, LOCK_EX | LOCK_NB);
    fclose($probe);
    echo json_encode(['children' => $GLOBALS['children'], 'logs' => $GLOBALS['logs'],
        'childLocks' => $GLOBALS['childLocks'], 'lockFree' => $free,
        'marker' => is_file('.rtorrentExecuteRun') ? file_get_contents('.rtorrentExecuteRun') : null]);
});
try {
PHP;
        $script = $this->pmssWriteFile($home.'/.rtorrentExecute.php', $shims."\n".$source
            ."\n} catch (\\RuntimeException \$error) {\n"
            ."if (\$error->getMessage() !== 'fixture complete') throw \$error;\n}\n");
        return $this->pmssRunInlinePhpJson('chdir('.var_export($home, true).'); define("MODE", '
            .var_export($mode, true).'); require '.var_export($script, true).';', [], '2>&1');
    }

    public function testDeletedBinaryYieldsOnlyAfterChildReturns(): void
    {
        $result = $this->runExecutor('deleted');
        $this->assertSame(1, $result['children']);
        $this->assertSame([true], $result['childLocks']);
        $this->assertTrue($result['lockFree']);
        $this->assertSame('0', $result['marker']);
        $this->assertTrue(in_array('executor stale; exiting for watchdog restart', $result['logs'], true));
    }

    public function testChangedScriptYieldsAndReleasesLock(): void
    {
        $result = $this->runExecutor('changed');
        $this->assertSame(1, $result['children']);
        $this->assertSame([true], $result['childLocks']);
        $this->assertTrue($result['lockFree']);
        $this->assertSame('0', $result['marker']);
    }

    public function testAlreadyDeletedBinaryYieldsBeforeFirstChild(): void
    {
        $result = $this->runExecutor('deletedNow');
        $this->assertSame(0, $result['children']);
        $this->assertTrue($result['lockFree']);
        $this->assertSame('0', $result['marker']);
    }

    public function testFreshAndUnavailableProbesKeepSupervising(): void
    {
        foreach (['fresh', 'suffixOnly', 'unreadableExe', 'unreadableInitial', 'unreadableCurrent'] as $mode) {
            $result = $this->runExecutor($mode);
            $this->assertSame(2, $result['children'], $mode);
            $this->assertSame([true, true], $result['childLocks'], $mode);
            $this->assertFalse($result['lockFree'], $mode);
            $this->assertTrue((int) $result['marker'] > 0, $mode);
        }
    }

    public function testExistingExecutorLockStillPreventsAnotherChild(): void
    {
        $result = $this->runExecutor('locked');
        $this->assertSame(0, $result['children']);
        $this->assertFalse($result['lockFree']);
        $this->assertSame(null, $result['marker']);
        $this->assertSame(['executor already running; exiting'], $result['logs']);
    }
}
