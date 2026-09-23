<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class RootShellDefaultsSafetyTest extends TestCase
{
    public function testCreatesMissingBashrcWithHistoricalDefaults(): void
    {
        $path = $this->pmssMakeTempDir('pmss-root-shell-create-').'/.bashrc';
        $messages = $this->runDefaults($path);

        $this->assertSame("alias ls='ls --color=auto'\nPATH=\$PATH:/scripts\n", file_get_contents($path));
        $this->pmssAssertMessagesContain($messages, 'Appended root shell defaults:');
    }

    public function testAppendsOnlyMissingDefaultsAndPreservesMode(): void
    {
        $path = $this->pmssMakeTempDir('pmss-root-shell-append-').'/.bashrc';
        file_put_contents($path, "alias ls='ls --color=auto'\ncustom=value\n");
        chmod($path, 0600);

        $this->runDefaults($path);

        $this->assertSame("alias ls='ls --color=auto'\ncustom=value\nPATH=\$PATH:/scripts\n", file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function testLeavesCompleteBashrcUntouched(): void
    {
        $path = $this->pmssMakeTempDir('pmss-root-shell-skip-').'/.bashrc';
        $contents = "custom=value\nalias ls='ls --color=auto'\nPATH=\$PATH:/scripts\n";
        file_put_contents($path, $contents);
        $inode = fileinode($path);

        $messages = $this->runDefaults($path);

        $this->assertSame($contents, file_get_contents($path));
        $this->assertSame($inode, fileinode($path));
        $this->pmssAssertMessagesContain($messages, '[SKIP] Root shell defaults already configured');
    }

    public function testRejectsSymlinkWithoutChangingItsTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-root-shell-link-');
        [$target, $link] = $this->pmssCreateSymlinkedFileOrSkip($root.'/target', $root.'/.bashrc', "custom=value\n");

        $messages = $this->runDefaults($link);

        $this->assertSame("custom=value\n", file_get_contents($target));
        $this->pmssAssertMessagesContain($messages, 'Unsafe root shell defaults target');
    }

    public function testRejectsNonRegularTarget(): void
    {
        $path = $this->pmssMakeTempDir('pmss-root-shell-directory-').'/.bashrc';
        mkdir($path);

        $messages = $this->runDefaults($path);

        $this->assertTrue(is_dir($path));
        $this->pmssAssertMessagesContain($messages, 'Unsafe root shell defaults target');
    }

    /** @return array<int, string> */
    private function runDefaults(string $path): array
    {
        return $this->pmssArrayLoggerMessages(static function (callable $logger) use ($path): void {
            \pmssConfigureRootShellDefaults($logger, $path);
        });
    }
}
