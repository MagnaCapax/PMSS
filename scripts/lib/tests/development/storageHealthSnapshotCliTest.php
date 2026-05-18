<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StorageHealthSnapshotCliTest extends TestCase
{
    public function testHelpShowsUsage(): void
    {
        $result = $this->runSnapshotCommand(['--help']);
        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('Usage: storageHealthSnapshot.php', $result['output']);
    }

    public function testWritesJsonlToExplicitPath(): void
    {
        $jsonPath = $this->pmssMakeTempPath('pmss-storage-health-snapshot-', '.jsonl');
        $result = $this->runSnapshotCommand(['--json', $jsonPath]);
        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('Storage health snapshot written to '.$jsonPath, $result['output']);
        $this->assertTrue(is_file($jsonPath), 'expected JSONL file to be created');
        $this->assertStringContainsString('"kind":"smart"', (string) file_get_contents($jsonPath));
    }

    public function testQuietSuppressesSuccessMessage(): void
    {
        $jsonPath = $this->pmssMakeTempPath('pmss-storage-health-snapshot-', '.jsonl');
        $result = $this->runSnapshotCommand(['--json', $jsonPath, '--quiet']);
        $this->assertSame(0, $result['rc']);
        $this->assertSame('', trim($result['output']));
        $this->assertTrue(is_file($jsonPath), 'expected quiet mode to still write the snapshot');
    }

    public function testInlineJsonOptionWritesSnapshot(): void
    {
        $jsonPath = $this->pmssMakeTempPath('pmss-storage-health-inline-', '.jsonl');
        $result = $this->runSnapshotCommand(['--json='.$jsonPath]);
        $this->assertSame(0, $result['rc']);
        $this->assertTrue(is_file($jsonPath), 'expected inline --json option to be honored');
        $this->assertStringContainsString('"device":"/dev/pmssfake0"', (string) file_get_contents($jsonPath));
    }

    public function testFailsWhenLogDirectoryCannotBeCreated(): void
    {
        $parentPath = $this->pmssMakeTempFile('pmss-storage-health-parent-');
        $result = $this->runSnapshotCommand(['--json', $parentPath.'/child.jsonl']);
        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Refusing unsafe storage health log path', $result['output']);
    }

    public function testRejectsSymlinkLogTargetBeforeReading(): void
    {
        $target = $this->pmssMakeTempFile('pmss-storage-health-target-');
        $link = $this->pmssMakeTempDir('pmss-storage-health-link-').'/events.jsonl';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $result = $this->runSnapshotCommand(['--json', $link]);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Refusing unsafe storage health log path', $result['output']);
        $this->assertSame('', (string) file_get_contents($target));
    }

    public function testRejectsSymlinkedLogParentBeforeCreatingDirectories(): void
    {
        $targetDir = $this->pmssMakeTempDir('pmss-storage-health-real-');
        $linkRoot = $this->pmssMakeTempDir('pmss-storage-health-linkroot-');
        $linkDir = $linkRoot.'/redirected';
        $this->pmssCreateSymlinkOrSkip($targetDir, $linkDir);

        $result = $this->runSnapshotCommand(['--json', $linkDir.'/nested/events.jsonl']);

        $this->assertSame(1, $result['rc']);
        $this->assertStringContainsString('Refusing unsafe storage health log path', $result['output']);
        $this->assertTrue(!is_dir($targetDir.'/nested'), 'must not create log directories through symlinked parents');
    }

    /** @return array{rc:int,output:string,lines:array<int,string>} */
    private function runSnapshotCommand(array $arguments): array
    {
        $stubDir = $this->pmssMakeLineOutputStub(
            'lsblk',
            ['pmssfake0 disk 1 PMSSSERIAL 1T'],
            'pmss-storage-health-lsblk-'
        );

        return $this->pmssRunRepoPhpScriptCommand(
            'scripts/util/storageHealthSnapshot.php',
            $arguments,
            $this->pmssPathPrefixedEnvironment($stubDir)
        );
    }
}
