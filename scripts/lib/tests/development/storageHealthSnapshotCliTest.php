<?php
namespace PMSS\Tests;

class StorageHealthSnapshotCliTest extends TestCase
{
    public function testHelpShowsUsage(): void
    {
        $this->pmssAssertRepoPhpScriptOutputContains('scripts/util/storageHealthSnapshot.php', ['--help'], ['Usage: storageHealthSnapshot.php'], $this->storageHealthSnapshotCommandEnvironment());
    }

    public function testJsonOptionWritesSnapshotVariants(): void
    {
        foreach ([
            ['pmss-storage-health-snapshot-', false, false, 'Storage health snapshot written to ', '"kind":"smart"'],
            ['pmss-storage-health-snapshot-', true, false, '', ''],
            ['pmss-storage-health-inline-', false, true, null, '"device":"/dev/pmssfake0"'],
        ] as [$prefix, $quiet, $inline, $outputPrefix, $jsonNeedle]) {
            $jsonPath = $this->pmssMakeTempPath($prefix, '.jsonl');
            $arguments = $inline ? ['--json='.$jsonPath] : ['--json', $jsonPath];
            if ($quiet) {
                $arguments[] = '--quiet';
            }

            $result = $this->runSnapshotCommand($arguments);

            $this->assertSame(0, $result['rc']);
            $this->assertTrue(is_file($jsonPath), 'expected JSONL file to be created');
            if ($outputPrefix === '') {
                $this->assertSame('', trim($result['output']));
            } elseif ($outputPrefix !== null) {
                $this->assertStringContainsString($outputPrefix.$jsonPath, $result['output']);
            }
            if ($jsonNeedle !== '') {
                $this->assertStringContainsString($jsonNeedle, (string) file_get_contents($jsonPath));
            }
        }
    }

    public function testFailsWhenLogDirectoryCannotBeCreated(): void
    {
        $parentPath = $this->pmssMakeTempFile('pmss-storage-health-parent-');
        $this->assertSnapshotRejectsUnsafeLogTarget(['--json', $parentPath.'/child.jsonl']);
    }

    public function testRejectsSymlinkLogTargetBeforeReading(): void
    {
        $target = $this->pmssMakeTempFile('pmss-storage-health-target-');
        $link = $this->pmssMakeTempDir('pmss-storage-health-link-').'/events.jsonl';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $result = $this->assertSnapshotRejectsUnsafeLogTarget(['--json', $link]);

        $this->assertSame('', (string) file_get_contents($target));
    }

    public function testRejectsSymlinkedLogParentBeforeCreatingDirectories(): void
    {
        $targetDir = $this->pmssMakeTempDir('pmss-storage-health-real-');
        $linkRoot = $this->pmssMakeTempDir('pmss-storage-health-linkroot-');
        $linkDir = $linkRoot.'/redirected';
        $this->pmssCreateSymlinkOrSkip($targetDir, $linkDir);

        $this->assertSnapshotRejectsUnsafeLogTarget(['--json', $linkDir.'/nested/events.jsonl']);

        $this->assertTrue(!is_dir($targetDir.'/nested'), 'must not create log directories through symlinked parents');
    }

    /** @return array{rc:int,output:string,lines:array<int,string>} */
    private function assertSnapshotRejectsUnsafeLogTarget(array $arguments): array
    {
        $result = $this->runSnapshotCommand($arguments);
        $this->pmssAssertArraySubsetSame(['rc' => 1], $result, 'snapshot rejection ');
        $this->assertStringContainsString('Refusing unsafe storage health log path', $result['output']);
        return $result;
    }

    /** @return array{rc:int,output:string,lines:array<int,string>} */
    private function runSnapshotCommand(array $arguments): array
    {
        return $this->pmssRunRepoPhpScriptCommand(
            'scripts/util/storageHealthSnapshot.php',
            $arguments,
            $this->storageHealthSnapshotCommandEnvironment()
        );
    }

    /** Return the command environment shared by storage health CLI assertions. */
    private function storageHealthSnapshotCommandEnvironment(): array
    {
        $stubDir = $this->pmssMakeLineOutputStub(
            'lsblk',
            ['pmssfake0 disk 1 PMSSSERIAL 1T'],
            'pmss-storage-health-lsblk-'
        );

        return $this->pmssPathPrefixedEnvironment($stubDir);
    }
}
