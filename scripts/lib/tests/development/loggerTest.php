<?php
namespace PMSS\Tests;

// Tests for scripts/lib/logger.php: Logger and logmsg wrapper
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/logger.php';

class LoggerTest extends TestCase
{
    public function testLoggerWritesToCustomDir(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-logs-', 0700);
        $logger = new \Logger(__FILE__, $dir);
        $logger->msg('hello custom');
        $base = basename(__FILE__, '.php');
        $path = rtrim($dir, '/').'/'.$base.'.log';
        $this->pmssAssertFileContainsAllStrings($path, ['hello custom']);
    }

    public function testLoggerFallsBackToTmp(): void
    {
        // Attempt writing to a path we cannot write; should fall back to /tmp/<base>.log
        $logger = new \Logger('/no/perm/logger-fallback-test.php', '/');
        $path = '/tmp/'.basename('/no/perm/logger-fallback-test.php', '.php').'.log';
        @unlink($path);
        $logger->msg('fallback line');
        $this->pmssAssertFileContainsAllStrings($path, ['fallback line']);
        // The shared fallback directory must not expose the log to other accounts.
        clearstatcache(true, $path);
        $this->assertSame(0600, fileperms($path) & 0777);
        @unlink($path);
    }

    public function testLogMsgWrapperFallbackToTmp(): void
    {
        // update.php registers its own logmsg helper; verify it falls back to /tmp when primary is unavailable
        $_SERVER['SCRIPT_NAME'] = __DIR__.'/Runner.php';
        $fallbackPath = '/tmp/'.basename($_SERVER['SCRIPT_NAME'], '.php').'.log';

        @unlink($fallbackPath);
        \logmsg('wrapper line');

        $this->pmssAssertFileContainsAllStrings($fallbackPath, ['wrapper line']);
        @unlink($fallbackPath);
    }

    public function testSharedLogRefusesHardLinkedFile(): void
    {
        // A second name on the file means someone else can reach the bytes; nothing may be appended.
        $dir = $this->pmssMakeTempDir('pmss-log-shared-', 0700);
        $target = $this->pmssWriteFile($dir.'/target', "kept\n");
        $this->assertTrue(link($target, $dir.'/shared.log'));
        $this->assertFalse(\pmssLogAppendTimestampedLine($dir.'/shared.log', 'leaked', '[Y-m-d H:i:s] ', '', 0600));
        $this->assertSame("kept\n", file_get_contents($target));
    }

    /** Run update.php's own bootstrap logmsg in a child process with an unwritable primary log directory. */
    private function runUpdateBootstrapLogmsg(string $scriptName, string $message): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/update.php');
        $start = strpos($source, 'function pmssEnsureDirectory(');
        $end = strpos($source, "if (!function_exists('pmssCorrelationId'))");
        $this->assertTrue($start !== false && $end !== false && $end > $start, 'update.php bootstrap logger located');
        $code = str_replace("'/var/log/pmss'", "'/proc/pmss-no-such-log-dir'", substr($source, $start, $end - $start));
        $script = $this->pmssMakeTempDir('pmss-update-logmsg-', 0700).'/run.php';
        $this->pmssWriteFile($script, "<?php\n\$_SERVER['SCRIPT_NAME'] = ".var_export($scriptName, true).";\n".$code
            ."\nlogmsg(".var_export($message, true).");\n");
        $rc = $this->pmssExecArgvCommand([PHP_BINARY, $script], [], '>/dev/null 2>&1')['rc'];
        $this->assertSame(0, $rc, 'update.php bootstrap logmsg ran');
    }

    public function testUpdateBootstrapLogmsgWritesOwnerOnlyFallback(): void
    {
        $fallbackPath = '/tmp/pmss-bootstrap-fallback-test.log';
        @unlink($fallbackPath);
        $this->runUpdateBootstrapLogmsg('/no/perm/pmss-bootstrap-fallback-test.php', 'bootstrap line');
        $this->pmssAssertFileContainsAllStrings($fallbackPath, ['bootstrap line']);
        clearstatcache(true, $fallbackPath);
        $this->assertSame(0600, fileperms($fallbackPath) & 0777);
        @unlink($fallbackPath);
    }

    public function testUpdateBootstrapLogmsgRefusesHardLinkedFallback(): void
    {
        // update.php's own fallback writer must refuse the same shape before anything reaches /tmp.
        $fallbackPath = '/tmp/pmss-bootstrap-linked-test.log';
        @unlink($fallbackPath);
        $dir = $this->pmssMakeTempDir('pmss-log-wrapper-', 0700);
        $this->pmssWriteFile($dir.'/target', "kept\n");
        $this->assertTrue(link($dir.'/target', $fallbackPath));
        $this->runUpdateBootstrapLogmsg('/no/perm/pmss-bootstrap-linked-test.php', 'wrapper leaked line');
        $this->assertSame("kept\n", file_get_contents($dir.'/target'));
        @unlink($fallbackPath);
    }

    public function testSharedLogOwnerCheckRejectsAnotherAccountsFile(): void
    {
        // Runs without root: the owner rule itself, fed lstat data of a single-name regular file.
        $own = $this->pmssMakeTempDir('pmss-log-own-', 0700).'/fresh.log';
        $this->assertTrue(\pmssLogSharedFileIsOwn($own, 0600));
        clearstatcache(true, $own);
        $this->assertSame(0600, fileperms($own) & 0777);
        $stat = lstat($own);
        $this->assertTrue(\pmssLogStatIsOwnSingleRegular($stat));
        $stat['uid'] = $stat['uid'] + 1;
        $this->assertFalse(\pmssLogStatIsOwnSingleRegular($stat));
    }

    public function testSharedLogNarrowsOwnWiderLeftoverBeforeWriting(): void
    {
        // A leftover of ours from an older version (0644) is narrowed before the new line lands in it.
        $dir = $this->pmssMakeTempDir('pmss-log-leftover-', 0700);
        $path = $this->pmssWriteFile($dir.'/shared.log', "old\n");
        chmod($path, 0644);
        $this->assertTrue(\pmssLogAppendTimestampedLine($path, 'new line', '[Y-m-d H:i:s] ', '', 0600));
        clearstatcache(true, $path);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->pmssAssertFileContainsAllStrings($path, ['old', 'new line']);
    }

    public function testSharedLogRefusesDirectoryOthersCanRename(): void
    {
        // A directory others can write without the sticky bit lets them swap the checked file: refuse it.
        $open = $this->pmssMakeTempDir('pmss-log-open-', 0700);
        chmod($open, 0777);
        $this->assertFalse(\pmssLogAppendTimestampedLine($open.'/shared.log', 'refused', '[Y-m-d H:i:s] ', '', 0600));
        $this->assertFalse(file_exists($open.'/shared.log'));
        $sticky = $this->pmssMakeTempDir('pmss-log-sticky-', 0700);
        chmod($sticky, 01777);
        $this->assertTrue(\pmssLogAppendTimestampedLine($sticky.'/shared.log', 'allowed', '[Y-m-d H:i:s] ', '', 0600));
        $this->pmssAssertFileContainsAllStrings($sticky.'/shared.log', ['allowed']);
        chmod($open, 0700);
        chmod($sticky, 0700);
    }

    public function testSharedLogRefusesFileOwnedByAnotherAccount(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new SkipTest('Root-only ownership fixture');
        }
        $account = posix_getpwnam('nobody');
        if (!is_array($account)) throw new SkipTest('Fixture account unavailable');
        // Another account created the name first: root must neither write to it nor re-mode it.
        $dir = $this->pmssMakeTempDir('pmss-log-foreign-', 0700);
        $path = $this->pmssWriteFile($dir.'/shared.log', "theirs\n");
        chmod($path, 0644);
        chown($path, $account['uid']);
        $this->assertFalse(\pmssLogAppendTimestampedLine($path, 'leaked', '[Y-m-d H:i:s] ', '', 0600));
        clearstatcache(true, $path);
        $this->assertSame("theirs\n", file_get_contents($path));
        $this->assertSame(0644, fileperms($path) & 0777);
        $fallbackPath = '/tmp/pmss-bootstrap-foreign-test.log';
        @unlink($fallbackPath);
        $this->pmssWriteFile($fallbackPath, "theirs\n");
        chmod($fallbackPath, 0644);
        chown($fallbackPath, $account['uid']);
        $this->runUpdateBootstrapLogmsg('/no/perm/pmss-bootstrap-foreign-test.php', 'wrapper leaked line');
        clearstatcache(true, $fallbackPath);
        $this->assertSame("theirs\n", file_get_contents($fallbackPath));
        $this->assertSame(0644, fileperms($fallbackPath) & 0777);
        @unlink($fallbackPath);
    }

    public function testLoggerSupportsCustomBaseName(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-logs-custom-', 0700);
        $logger = new \Logger(__FILE__, $dir, $dir, 'pmss-update');
        $logger->msg('custom base');

        $path = $dir.'/pmss-update.log';
        $this->pmssAssertFileContainsAllStrings($path, ['custom base']);
    }
}
