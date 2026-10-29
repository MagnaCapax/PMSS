<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/pathSafety.php';
require_once __DIR__.'/../common/TestCase.php';

class PathShellTargetSafetyTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-path-shell-target-']; }

    public function testAbsolutePathRetainsItsLexicalPolicy(): void
    {
        // Absolute callers keep their edge-slash and control-byte policy around the shared relative check.
        foreach ([['', false], ['/', false], ['//', false], ['/one', true], ['//one///', true],
            ['/one/two/', true], ['/one//two', false], ['/one/./two', false], ['/one/../two', false],
            ['/one/.../two', true], ["/one\ntwo", true], ["/one\rtwo", true], ["/one\0two", false]] as [$path, $safe]) {
            $this->assertSame($safe, \pmssPathAbsoluteStringIsSafe($path), bin2hex($path));
        }
        $this->assertTrue(\pmssPathAbsoluteStringIsSafe('/', ['allowRoot' => true]));
        $this->assertFalse(\pmssPathAbsoluteStringIsSafe('/one/', ['allowTrailingSlash' => false]));
        $this->assertFalse(\pmssPathAbsoluteStringIsSafe("/one\ntwo", ['allowWhitespace' => false]));
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

    public function testShellTargetRejectsNulBeforeFilesystemAccess(): void
    {
        $path = $this->tempDir.'/state.dat';
        file_put_contents($path, 'unchanged');

        // Cover literal paths and every character that selects the glob branch.
        foreach (["\0", "\0".$path, $path."\0", $path."\0ignored",
            $this->tempDir."/\0*", $this->tempDir."/*\0",
            $this->tempDir."/?\0", $this->tempDir."/[s]\0", $this->tempDir."/]\0"] as $invalid) {
            $this->assertSame([], \pmssPathShellTargetPaths($invalid));
            $this->assertSame(null, \pmssPathShellTarget($invalid));
        }

        $this->assertSame('unchanged', file_get_contents($path));
    }

    public function testShellTargetPreservesGlobOrderAndLiteralShellCharacters(): void
    {
        $paths = [$this->tempDir."/a 'quoted'.dat", $this->tempDir.'/b file.dat'];
        foreach ($paths as $path) file_put_contents($path, 'ok');

        $this->assertSame($paths, \pmssPathShellTargetPaths($this->tempDir.'/*.dat'));
        $this->assertSame(implode(' ', array_map('escapeshellarg', $paths)), \pmssPathShellTarget($this->tempDir.'/*.dat'));
        foreach ($paths as $path) {
            $this->assertSame([$path], \pmssPathShellTargetPaths($path));
            $this->assertSame(escapeshellarg($path), \pmssPathShellTarget($path));
        }
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
