<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SkeletonInstallerPermissionsTest extends TestCase
{
    public function testUserInstallerScriptsAreExecutable(): void
    {
        foreach (['install-ai-tools.sh', 'install-media-stack.sh'] as $name) {
            $path = $this->pmssRepoPath('etc/skel/'.$name);
            $mode = is_file($path) ? fileperms($path) : false;

            $this->assertTrue($mode !== false, 'Expected skeleton installer to exist: '.$name);
            $this->assertTrue(($mode & 0100) !== 0, 'Expected skeleton installer to be executable: '.$name);
        }
    }
}
