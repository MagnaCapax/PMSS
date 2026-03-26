<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update.php';

class UpdateUserFileSafeWriteTest extends TestCase
{
    private $homeRoot;
    private $user;
    private $skelDirName;
    private $skelDirPath;
    private $envBackup = [];

    protected function setUp(): void
    {
        $skelBase = \pmssSkeletonBase();
        if ($skelBase === '/etc/skel') {
            throw new SkipTest('PMSS_SKEL_DIR not set to a temp path');
        }

        $this->user = 'user'.bin2hex(random_bytes(2));
        $this->homeRoot = sys_get_temp_dir().'/pmss-userfile-'.bin2hex(random_bytes(4));
        @mkdir($this->homeRoot, 0755, true);

        $this->envBackup = $this->pmssCaptureEnv(['PMSS_HOME_DIR']);
        putenv('PMSS_HOME_DIR='.$this->homeRoot);

        $this->skelDirName = 'pmss-userfile-'.bin2hex(random_bytes(3));
        $this->skelDirPath = $skelBase.'/'.$this->skelDirName;
        @mkdir($this->skelDirPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->pmssRestoreEnvMap($this->envBackup);
        $this->cleanup($this->homeRoot);
        $this->cleanup($this->skelDirPath);
    }

    public function testCreatesParentDirectoriesForMissingPath(): void
    {
        $home = $this->ensureUserHome();
        $relative = $this->skelRelative('nested/dir/config.txt');
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'alpha');

        \updateUserFile($relative, $this->user);

        $target = $home.'/'.$relative;
        $this->assertTrue(is_file($target));
        $this->assertEquals('alpha', file_get_contents($target));
        $this->assertTrue(is_dir($home.'/'.$this->skelDirName.'/nested/dir'));
    }

    public function testIdempotentWhenContentMatches(): void
    {
        $home = $this->ensureUserHome();
        $relative = $this->skelRelative('same.txt');
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'same');

        $target = $home.'/'.$relative;
        @mkdir(dirname($target), 0755, true);
        file_put_contents($target, 'same');
        chmod($target, 0644);
        $beforePerms = fileperms($target) & 0777;

        \updateUserFile($relative, $this->user);

        $afterPerms = fileperms($target) & 0777;
        $this->assertEquals($beforePerms, $afterPerms);
        $this->assertEquals('same', file_get_contents($target));
    }

    public function testUpdatesWhenContentDiffers(): void
    {
        $home = $this->ensureUserHome();
        $relative = $this->skelRelative('diff.txt');
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'new');

        $target = $home.'/'.$relative;
        @mkdir(dirname($target), 0755, true);
        file_put_contents($target, 'old');

        \updateUserFile($relative, $this->user);

        $this->assertEquals('new', file_get_contents($target));
        $leftovers = glob(dirname($target).'/pmss-userfile-*');
        $this->assertFalse(is_array($leftovers) && count($leftovers) !== 0);
    }

    public function testSkipsWhenHomeMissing(): void
    {
        $relative = $this->skelRelative('missing-home.txt');
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'data');

        $target = $this->homeRoot.'/'.$this->user.'/'.$relative;
        \updateUserFile($relative, $this->user);

        $this->assertFalse(file_exists($target));
    }

    public function testSkipsWhenTargetIsDirectory(): void
    {
        $home = $this->ensureUserHome();
        $relative = $this->skelRelative('dir-target.txt');
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'data');

        $target = $home.'/'.$relative;
        @mkdir($target, 0755, true);

        \updateUserFile($relative, $this->user);

        $this->assertTrue(is_dir($target));
    }

    public function testCopyToUserSpaceReturnsFalseWhenParentDirectoryMissing(): void
    {
        $source = $this->pmssMakeTempFile('pmss-copy-source-');
        file_put_contents($source, 'data');

        $target = $this->homeRoot.'/'.$this->user.'/missing/target.txt';

        $this->assertFalse(\copyToUserSpace($source, $target, $this->user));
        $this->assertFalse(is_file($target));
    }

    public function testCopyToUserSpaceReturnsTrueWhenCopySucceeds(): void
    {
        $home = $this->ensureUserHome();
        $source = $this->pmssMakeTempFile('pmss-copy-source-');
        $target = $home.'/copied.txt';
        file_put_contents($source, 'payload');

        $this->assertTrue(\copyToUserSpace($source, $target, $this->user));
        $this->assertEquals('payload', file_get_contents($target));
    }

    private function ensureUserHome(): string
    {
        $home = $this->homeRoot.'/'.$this->user;
        if (!is_dir($home)) {
            @mkdir($home, 0755, true);
        }
        return $home;
    }

    private function skelRelative(string $suffix): string
    {
        return $this->skelDirName.'/'.$suffix;
    }

}
