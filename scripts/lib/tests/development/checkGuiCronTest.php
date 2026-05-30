<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/cron/checkGui.php';
require_once __DIR__.'/../common/TestCase.php';

class CheckGuiCronTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-check-gui-');
    }

    public function testRootCronSchedulesCheckGui(): void
    {
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/root.cron', '/scripts/cron/checkGui.php', 'root.cron should schedule checkGui.php');
    }

    public function testCheckGuiRepairsCoreUserspacePaths(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/checkGui.php',
            [
                'pmssCheckGuiEnsureUserDirectory($wwwDir',
                'pmssCheckGuiEnsureUserDirectory($dataDir',
                'pmssCheckGuiRestoreUserIndex',
            ],
            'checkGui should keep core userspace repair wiring: '
        );
    }

    public function testEnsureUserDirectoryCreatesSafeDirectory(): void
    {
        $homeDir = $this->tempDir.'/dummy';
        $this->pmssEnsureDir($homeDir);
        $messages = [];

        $this->assertTrue(\pmssCheckGuiEnsureUserDirectory(
            $homeDir.'/www',
            $this->pmssCurrentOwner(),
            'www',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertTrue(is_dir($homeDir.'/www'));
        $this->assertEquals(0755, fileperms($homeDir.'/www') & 0777);
    }

    public function testEnsureUserDirectoryRejectsTraversalOutsideHome(): void
    {
        $homeDir = $this->tempDir.'/dummy';
        $outsideDir = $this->tempDir.'/outside';
        $this->pmssEnsureDir($homeDir);
        $this->pmssEnsureDir($outsideDir);
        $messages = [];

        $this->assertFalse(\pmssCheckGuiEnsureUserDirectory(
            $homeDir.'/../outside/www',
            'dummy',
            'www',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertFalse(file_exists($outsideDir.'/www'));
        $this->pmssAssertMessagesContain($messages, 'unsafe www directory target');
    }

    public function testEnsureUserDirectoryRejectsSymlinkedTarget(): void
    {
        $homeDir = $this->tempDir.'/dummy';
        $outsideDir = $this->tempDir.'/outside';
        $this->pmssEnsureDir($homeDir);
        $this->pmssEnsureDir($outsideDir);
        symlink($outsideDir, $homeDir.'/www');
        $messages = [];

        $this->assertFalse(\pmssCheckGuiEnsureUserDirectory(
            $homeDir.'/www',
            'dummy',
            'www',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertTrue(is_link($homeDir.'/www'));
        $this->pmssAssertMessagesContain($messages, 'unsafe www directory target');
    }

    public function testRestoreUserIndexCopiesSafeSkeletonSource(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/index.php';
        $this->pmssWriteFile($sourceFile, "<?php echo 'ok';\n");
        $messages = [];

        $this->assertTrue(\pmssCheckGuiRestoreUserIndex(
            $homeDir.'/www/index.php',
            $sourceFile,
            $this->pmssCurrentOwner(),
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertEquals("<?php echo 'ok';\n", file_get_contents($homeDir.'/www/index.php'));
    }

    public function testRestoreUserIndexRejectsSymlinkTarget(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $sourceFile = $this->tempDir.'/skel/index.php';
        $outsideFile = $this->tempDir.'/outside.php';
        $this->pmssWriteFile($sourceFile, "<?php echo 'ok';\n");
        $this->pmssWriteFile($outsideFile, "outside\n");
        symlink($outsideFile, $homeDir.'/www/index.php');
        $messages = [];

        $this->assertFalse(\pmssCheckGuiRestoreUserIndex(
            $homeDir.'/www/index.php',
            $sourceFile,
            'dummy',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertEquals("outside\n", file_get_contents($outsideFile));
        $this->pmssAssertMessagesContain($messages, 'unsafe index.php target');
    }

    public function testRestoreUserIndexRejectsSymlinkSource(): void
    {
        $homeDir = $this->pmssEnsureUserWebHome($this->tempDir, 'dummy');
        $realSource = $this->tempDir.'/skel-real/index.php';
        $sourceFile = $this->tempDir.'/skel/index.php';
        $this->pmssWriteFile($realSource, "<?php echo 'ok';\n");
        $this->pmssEnsureDir(dirname($sourceFile));
        symlink($realSource, $sourceFile);
        $messages = [];

        $this->assertFalse(\pmssCheckGuiRestoreUserIndex(
            $homeDir.'/www/index.php',
            $sourceFile,
            'dummy',
            $this->pmssMakeArrayLogger($messages),
            $homeDir
        ));
        $this->assertFalse(file_exists($homeDir.'/www/index.php'));
        $this->pmssAssertMessagesContain($messages, 'missing skeleton source');
    }
}
