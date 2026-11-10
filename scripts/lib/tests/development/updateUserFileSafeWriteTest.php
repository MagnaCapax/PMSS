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

    protected function setUp(): void
    {
        $skelBase = \pmssSkeletonBase();
        if ($skelBase === '/etc/skel') {
            throw new SkipTest('PMSS_SKEL_DIR not set to a temp path');
        }

        $this->user = $this->pmssCurrentOwner();
        $this->homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-userfile-');

        $this->pmssAssignTempDirProperty('skelDirPath', 'pmss-userfile', 0755, $skelBase);
        $this->skelDirName = basename($this->skelDirPath);
    }

    public function testCreatesParentDirectoriesForMissingPath(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $relative = $this->skelDirName.'/nested/dir/config.txt';
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'alpha');

        \updateUserFile($relative, $this->user);

        $target = $home.'/'.$relative;
        $this->assertTrue(is_file($target));
        $this->assertEquals('alpha', file_get_contents($target));
        $this->assertTrue(is_dir($home.'/'.$this->skelDirName.'/nested/dir'));
    }

    public function testIdempotentWhenContentMatches(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $relative = $this->skelDirName.'/same.txt';
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'same');

        $target = $this->pmssWriteFile($home.'/'.$relative, 'same');
        chmod($target, 0644);
        $beforePerms = fileperms($target) & 0777;

        \updateUserFile($relative, $this->user);

        $afterPerms = fileperms($target) & 0777;
        $this->assertEquals($beforePerms, $afterPerms);
        $this->assertEquals('same', file_get_contents($target));
    }

    public function testUpdatesWhenContentDiffers(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $relative = $this->skelDirName.'/diff.txt';
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'new');

        $target = $this->pmssWriteFile($home.'/'.$relative, 'old');

        \updateUserFile($relative, $this->user);

        $this->assertEquals('new', file_get_contents($target));
        $leftovers = glob(dirname($target).'/pmss-userfile-*');
        $this->assertFalse(is_array($leftovers) && count($leftovers) !== 0);
    }

    public function testSkipsWhenHomeMissing(): void
    {
        $relative = $this->skelDirName.'/missing-home.txt';
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'data');

        $target = $this->homeRoot.'/'.$this->user.'/'.$relative;
        \updateUserFile($relative, $this->user);

        $this->assertFalse(file_exists($target));
    }

    public function testSkipsWhenTargetIsDirectory(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $relative = $this->skelDirName.'/dir-target.txt';
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'data');

        $target = $home.'/'.$relative;
        @mkdir($target, 0755, true);

        \updateUserFile($relative, $this->user);

        $this->assertTrue(is_dir($target));
    }

    public function testSkipsTraversalPathBeforeCopyingOutsideHome(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $this->pmssWriteFile(dirname(\pmssSkeletonBase()).'/escape.txt', 'escape');

        $outsideTarget = dirname($home).'/escape.txt';
        \updateUserFile('../escape.txt', $this->user);

        $this->assertFalse(file_exists($outsideTarget));
    }

    public function testSkipsAbsoluteRelativeName(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), '/absolute.txt', 'data');

        \updateUserFile('/absolute.txt', $this->user);

        $this->assertFalse(file_exists($home.'/absolute.txt'));
    }

    public function testSkipsControlCharacterPath(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $relative = $this->skelDirName."/bad\nname.txt";
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'data');

        \updateUserFile($relative, $this->user);

        $this->assertFalse(file_exists($home.'/'.$relative));
    }

    public function testSkipsUnsafeUserPathSegment(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $relative = $this->skelDirName.'/unsafe-user.txt';
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'data');

        \updateUserFile($relative, '..');

        $this->assertFalse(file_exists(dirname($home).'/../'.$relative));
    }

    public function testSkipsExistingSymlinkTarget(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $relative = $this->skelDirName.'/linked-target.txt';
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'new-data');

        $outside = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-linked-target-'), 'keep-data');
        $target = $home.'/'.$relative;
        @mkdir(dirname($target), 0755, true);
        $this->pmssCreateSymlinkOrSkip($outside, $target);

        \updateUserFile($relative, $this->user);

        $this->assertTrue(is_link($target));
        $this->assertEquals('keep-data', file_get_contents($outside));
    }

    public function testSkipsHomeSymlinkEscapingHomeRoot(): void
    {
        $outside = $this->pmssMakeTempDir('pmss-home-escape-');
        $relative = $this->skelDirName.'/home-escape.txt';
        $this->pmssWriteRelativeFile(\pmssSkeletonBase(), $relative, 'escape-data');
        $this->pmssCreateSymlinkOrSkip($outside, $this->homeRoot.'/'.$this->user);

        \updateUserFile($relative, $this->user);

        $this->assertFalse(is_file($outside.'/'.$relative));
    }

    public function testCopyToUserSpaceReturnsFalseWhenParentDirectoryMissing(): void
    {
        $source = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-copy-source-'), 'data');

        $target = $this->homeRoot.'/'.$this->user.'/missing/target.txt';

        $this->assertFalse(\copyToUserSpace($source, $target, $this->user));
        $this->assertFalse(is_file($target));
    }

    public function testCopyToUserSpaceRejectsRootTarget(): void
    {
        $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $source = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-copy-source-'), 'data');

        $this->assertFalse(\copyToUserSpace($source, '/', $this->user));
    }

    public function testCopyToUserSpaceRejectsSymlinkedParentEscape(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $outside = $this->pmssMakeTempDir('pmss-copy-escape-');
        $link = $home.'/linked-parent';
        $this->pmssCreateSymlinkOrSkip($outside, $link);

        $source = $this->pmssWriteFile($this->pmssMakeTempFile('pmss-copy-source-'), 'data');

        $this->assertFalse(\copyToUserSpace($source, $link.'/copied.txt', $this->user));
        $this->assertFalse(is_file($outside.'/copied.txt'));
    }

    public function testCopyToUserSpaceReturnsTrueWhenCopySucceeds(): void
    {
        $home = $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $source = $this->pmssMakeTempFile('pmss-copy-source-');
        $target = $home.'/copied.txt';
        $this->pmssWriteFile($source, 'payload');

        $this->assertTrue(\copyToUserSpace($source, $target, $this->user));
        $this->assertEquals('payload', file_get_contents($target));
    }

}
