<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/runtime/commands.php';

final class UserEntryMetadataCommandTest extends TestCase
{
    public function testModeWalkSkipsLinkedEntriesAndKeepsOrdinaryFiles(): void
    {
        $root = $this->pmssMakeTempDir('pmss-entry-mode-');
        $tree = $this->pmssEnsureDir($root.'/tree');
        $regular = $this->pmssWriteFile($tree.'/regular', 'data');
        $linked = $this->pmssWriteFile($tree.'/linked', 'data');
        $outside = $this->pmssWriteFile($root.'/outside', 'keep');
        $this->assertTrue(link($linked, $root.'/hardlink'));
        $this->assertTrue(symlink($outside, $tree.'/link'));
        chmod($regular, 0600);
        chmod($linked, 0600);
        chmod($outside, 0600);

        $output = [];
        $status = 1;
        exec(\pmssUserEntryChmodCommand($tree, 0755, true), $output, $status);

        $this->assertSame(0, $status);
        clearstatcache(true);
        $this->assertSame(0755, fileperms($regular) & 0777);
        $this->assertSame(0600, fileperms($linked) & 0777);
        $this->assertSame(0600, fileperms($outside) & 0777);
        $this->assertTrue(is_link($tree.'/link'));
    }

    public function testCommandsQuotePathsAndBoundSingleEntryChanges(): void
    {
        $path = "/tmp/account path'quoted";
        $owner = 'demo:demo';
        $ownerCommand = \pmssUserEntryChownCommand($path, $owner);
        $modeCommand = \pmssUserEntryChmodCommand($path, 0750);

        $this->assertStringContainsString(escapeshellarg($path).' -maxdepth 0 -not -type l', $ownerCommand);
        $this->assertStringContainsString('-links 1 \\) -exec chown -h '.escapeshellarg($owner), $ownerCommand);
        $this->assertStringContainsString(escapeshellarg($path).' -maxdepth 0 -not -type l', $modeCommand);
        $this->assertStringContainsString('-links 1 \\) -exec chmod 0750', $modeCommand);
        $this->assertThrows(\InvalidArgumentException::class, static function () use ($path): void {
            \pmssUserEntryChmodCommand($path, -1);
        });
        $this->assertThrows(\InvalidArgumentException::class, static function () use ($path): void {
            \pmssUserEntryChmodCommand($path, 010000);
        });
    }

    public function testUpdaterCallSitesUseTheSharedCommands(): void
    {
        $rutorrent = $this->pmssReadRepoFile('scripts/lib/update/users/rutorrent.php');
        $http = $this->pmssReadRepoFile('scripts/lib/update/users/http.php');
        $this->assertSame(5, substr_count($rutorrent, 'pmssUserEntryChownCommand('));
        $this->assertSame(2, substr_count($rutorrent, 'pmssUserEntryChmodCommand('));
        $this->assertStringContainsString('pmssUserEntryChownCommand($irssiDir, $user.\':\'.$user, true)', $http);
        $this->assertFalse(strpos($rutorrent, 'chown -R') !== false);
        $this->assertFalse(strpos($rutorrent, 'chmod -R') !== false);
    }
}
