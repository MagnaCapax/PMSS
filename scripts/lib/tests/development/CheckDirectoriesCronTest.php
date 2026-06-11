<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/cron/checkDirectories.php';

class CheckDirectoriesCronTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-check-dirs-');
    }

    public function testRequiredDirectoriesKeepParentsBeforeChildren(): void
    {
        $dirs = \pmssCheckDirectoriesRequiredDirectories();
        $this->assertTrue(array_search('/var/log/pmss', $dirs, true) < array_search('/var/log/pmss/traffic', $dirs, true));
        $this->assertTrue(array_search('/var/run/pmss', $dirs, true) < array_search('/var/run/pmss/api', $dirs, true));
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

    public function testEnsureDirectoryRejectsEmptyPath(): void
    {
        $messages = [];

        $this->assertFalse(\pmssCheckDirectoriesEnsureDirectory('', $this->pmssMakeArrayLogger($messages), $this->pmssCurrentOwner()));
        $this->pmssAssertMessagesContain($messages, 'empty required directory path');
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
