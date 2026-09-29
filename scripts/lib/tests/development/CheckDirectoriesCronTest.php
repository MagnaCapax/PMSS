<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/cron/checkDirectories.php';

class CheckDirectoriesCronTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-check-dirs-']; }

    public function testRequiredDirectoriesKeepParentsBeforeChildren(): void
    {
        $dirs = \pmssCheckDirectoriesRequiredDirectories();
        $this->assertTrue(array_search('/var/log/pmss', $dirs, true) < array_search('/var/log/pmss/traffic', $dirs, true));
        $this->assertTrue(array_search('/var/run/pmss', $dirs, true) < array_search('/var/run/pmss/api', $dirs, true));
    }

    public function testPublicDirectoryIsSeparateAndRejectsSymlink(): void
    {
        $this->assertSame(['/var/lib/pmss/public'], \pmssCheckDirectoriesPublicDirectories());
        $messages = [];
        $target = $this->tempDir.'/target';
        $this->pmssEnsureDir($target, 0700);
        $link = $this->tempDir.'/public';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $this->assertFalse(\pmssCheckDirectoriesEnsurePublicDirectory($link, $this->pmssMakeArrayLogger($messages), posix_geteuid(), posix_getegid()));
        $this->assertSame(0700, fileperms($target) & 0777);
        $this->pmssAssertMessagesContain($messages, 'not a root-owned real directory');
    }

    public function testPublicDirectoryCreatesWithTraversalAndRefusesFile(): void
    {
        $messages = [];
        $path = $this->tempDir.'/public';
        $this->assertTrue(\pmssCheckDirectoriesEnsurePublicDirectory($path, $this->pmssMakeArrayLogger($messages), posix_geteuid(), posix_getegid()));
        $this->assertSame(0755, fileperms($path) & 0777);
        $file = $this->pmssWriteFile($this->tempDir.'/occupied', 'x');
        $this->assertFalse(\pmssCheckDirectoriesEnsurePublicDirectory($file, $this->pmssMakeArrayLogger($messages), posix_geteuid(), posix_getegid()));
        $this->assertFalse(\pmssCheckDirectoriesEnsurePublicDirectory($path, $this->pmssMakeArrayLogger($messages), posix_geteuid() + 1, posix_getegid()));
        $this->assertSame(0755, fileperms($path) & 0777);
    }

    public function testEnsureDirectoryCreatesAndNormalizesDirectory(): void
    {
        $messages = [];
        $dir = $this->tempDir.'/runtime';

        $this->assertTrue(\pmssCheckDirectoriesEnsureDirectory($dir, $this->pmssMakeArrayLogger($messages), $this->pmssCurrentOwner()));
        $this->assertTrue(is_dir($dir));
        $this->assertSame(0700, fileperms($dir) & 0777);
        $this->pmssAssertMessagesContain($messages, 'Created '.$dir);
    }

    public function testEnsureDirectoryRejectsExistingFile(): void
    {
        $messages = [];
        $file = $this->tempDir.'/not-a-directory';
        $this->pmssWriteFile($file, "occupied\n");

        $this->assertFalse(\pmssCheckDirectoriesEnsureDirectory($file, $this->pmssMakeArrayLogger($messages), $this->pmssCurrentOwner()));
        $this->assertTrue(is_file($file));
        $this->pmssAssertMessagesContain($messages, 'exists but is not a directory');
    }

    public function testEnsureDirectoryRejectsSymlinkWithoutChangingTarget(): void
    {
        $messages = [];
        $target = $this->tempDir.'/target';
        $this->pmssEnsureDir($target, 0755);
        $this->assertTrue(chmod($target, 0755));
        $link = $this->tempDir.'/runtime-link';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $this->assertFalse(\pmssCheckDirectoriesEnsureDirectory($link, $this->pmssMakeArrayLogger($messages), $this->pmssCurrentOwner()));
        clearstatcache(true, $target);
        $this->assertSame(0755, fileperms($target) & 0777);
        $this->pmssAssertMessagesContain($messages, 'exists but is not a directory');
    }

    public function testEnsureDirectoryRejectsEmptyPath(): void
    {
        $messages = [];

        $this->assertFalse(\pmssCheckDirectoriesEnsureDirectory('', $this->pmssMakeArrayLogger($messages), $this->pmssCurrentOwner()));
        $this->pmssAssertMessagesContain($messages, 'empty required directory path');
    }

    public function testMalformedDirectoryPathsFailSoftAndMainContinues(): void
    {
        $messages = [];
        $log = $this->pmssMakeArrayLogger($messages);
        foreach (["\0", "bad\0path", "bad\0", $this->tempDir."/\0child", $this->tempDir."/child\0"] as $path) {
            $this->pmssAssertNoPhpWarnings(function () use ($path, $log): void {
                $this->assertFalse(\pmssCheckDirectoriesEnsureDirectory($path, $log, $this->pmssCurrentOwner()));
                $this->assertFalse(\pmssCheckDirectoriesEnsurePublicDirectory($path, $log, posix_geteuid(), posix_getegid()));
            });
        }

        $created = $this->tempDir.'/after-malformed';
        $logger = new \Logger(__FILE__, $this->tempDir, $this->tempDir, 'checkDirectoriesMalformedTest');
        $this->assertSame(0, \pmssCheckDirectoriesMain($logger, ["bad\0path", $created]));
        $this->assertTrue(is_dir($created));
        $this->pmssAssertMessagesContain($messages, 'invalid required directory path');
        $this->pmssAssertMessagesContain($messages, 'invalid public directory path');
    }

    public function testMainContinuesAfterIndividualDirectoryFailure(): void
    {
        $logger = new \Logger(__FILE__, $this->tempDir, $this->tempDir, 'checkDirectoriesTest');
        $blocked = $this->tempDir.'/blocked';
        $created = $this->tempDir.'/created';
        $this->pmssWriteFile($blocked, "occupied\n");

        $this->assertSame(0, \pmssCheckDirectoriesMain($logger, [$blocked, $created]));
        $this->assertTrue(is_file($blocked));
        $this->assertTrue(is_dir($created));

        $log = (string) file_get_contents($this->tempDir.'/checkDirectoriesTest.log');
        $this->assertStringContainsAllStrings([
            'Verifying required directories',
            'exists but is not a directory',
            'Created '.$created,
        ], $log);
    }

    public function testOwnershipAndModeResultsAreChecked(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/runtime/directories.php', [
            'if (!@chown($thisDir, $owner))',
            'WARN: failed to set owner',
            'if (!@chmod($thisDir, 0700))',
            'WARN: failed to set mode 0700',
        ], 'checkDirectories should log failed normalization calls: ');
    }
}
