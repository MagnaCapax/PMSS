<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/pathSafety.php';
require_once __DIR__.'/../common/TestCase.php';

class PathShellTargetSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-path-shell-target-');
    }

    public function testShellTargetAcceptsExistingRegularFile(): void
    {
        $path = $this->tempDir.'/state.dat';
        file_put_contents($path, 'ok');

        $this->assertSame(escapeshellarg($path), \pmssPathShellTarget($path));
    }

    public function testShellTargetRejectsMissingPath(): void
    {
        $this->assertSame(null, \pmssPathShellTarget($this->tempDir.'/missing.dat'));
    }

    public function testShellTargetRejectsLeafSymlink(): void
    {
        [, $link] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/target.dat', $this->tempDir.'/link.dat', 'target');

        $this->assertSame(null, \pmssPathShellTarget($link));
    }

    public function testShellTargetRejectsSymlinkedAncestor(): void
    {
        [$realDir, $linkDir] = $this->pmssCreateSymlinkedDirectoryOrSkip($this->tempDir.'/real', $this->tempDir.'/link');
        file_put_contents($realDir.'/state.dat', 'target');

        $this->assertSame(null, \pmssPathShellTarget($linkDir.'/state.dat'));
    }

    public function testShellTargetGlobDropsSymlinkedMembers(): void
    {
        $safeA = $this->tempDir.'/a.php';
        $safeB = $this->tempDir.'/b.php';
        file_put_contents($safeA, 'a');
        file_put_contents($safeB, 'b');
        $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/target.dat', $this->tempDir.'/c.php', 'target');

        $target = \pmssPathShellTarget($this->tempDir.'/*.php');

        $this->assertSame(escapeshellarg($safeA).' '.escapeshellarg($safeB), $target);
    }

    public function testShellTargetGlobReturnsNullWhenOnlySymlinkedMembersMatch(): void
    {
        $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/target.dat', $this->tempDir.'/link.php', 'target');

        $this->assertSame(null, \pmssPathShellTarget($this->tempDir.'/*.php'));
    }
}
