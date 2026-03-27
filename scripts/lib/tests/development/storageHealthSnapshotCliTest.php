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
        $this->assertStringContainsString('Failed to create storage health log directory', $result['output']);
    }

    /** @return array{rc:int,output:string,lines:array<int,string>} */
    private function runSnapshotCommand(array $arguments): array
    {
        $stubDir = $this->pmssMakeLineOutputStub(
            'lsblk',
            ['pmssfake0 disk 1 PMSSSERIAL 1T'],
            'pmss-storage-health-lsblk-'
        );

        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->pmssRepoPath('scripts/util/storageHealthSnapshot.php'));
        foreach ($arguments as $argument) {
            $command .= ' '.escapeshellarg((string) $argument);
        }

        return $this->pmssExecShellCommand($command, [
            'PATH' => $stubDir.':'.(string) getenv('PATH'),
        ]);
    }
}
