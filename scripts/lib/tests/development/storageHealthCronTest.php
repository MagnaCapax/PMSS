<?php
namespace PMSS\Tests;

class StorageHealthCronTest extends TestCase
{
    public function testStartedCronWrapperAppendsSnapshotsQuietly(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new SkipTest('Storage health cron entrypoint requires root');
        }

        // /dev/null is readable without opening a real disk; all probes are stubs.
        $bin = $this->pmssMakeLineOutputStub('lsblk', ['null disk 1 Fixture PMSSSERIAL 1T'], 'pmss-storage-cron-bin-');
        $this->pmssWriteExecutableFiles($bin, [
            'smartctl' => "#!/bin/sh\nprintf '%s\\n' 'SMART overall-health self-assessment test result: PASSED'\n",
            'mdadm' => "#!/bin/sh\nexit 1\n",
        ]);
        $jsonPath = $this->pmssMakeTempPath('pmss-storage-cron-', '.jsonl');

        // Start the wrapper itself: requiring it or calling main masks the guard bug.
        foreach ([['--json', $jsonPath], ['--json='.$jsonPath]] as $index => $arguments) {
            $result = $this->pmssRunRepoPhpScriptCommand(
                'scripts/cron/storageHealthSnapshot.php', $arguments, ['PATH' => $bin]
            );
            $this->assertSame(0, $result['rc'], $result['output']);
            $this->assertSame('', $result['output'], 'cron must add --quiet');
            $this->assertTrue(is_file($jsonPath), 'cron must create the JSONL snapshot');

            $smartEntries = [];
            foreach (file($jsonPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $entry = $this->pmssDecodeJsonArray($line);
                if (($entry['kind'] ?? '') === 'smart') $smartEntries[] = $entry;
            }
            // Repeated invocations append exactly once and preserve the parsed probe.
            $this->assertSame($index + 1, count($smartEntries));
            $this->assertSame('/dev/null', $smartEntries[$index]['device']);
            $this->assertSame('PASSED', $smartEntries[$index]['metrics']['health']);
        }
    }

    public function testCronAndLogrotatePoliciesCoverStorageHealthSnapshots(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/root.cron',
            '0 6,18 * * *   root    /scripts/cron/storageHealthSnapshot.php >> /var/log/pmss/storageHealthSnapshot.log 2>&1',
            'root.cron should schedule storageHealthSnapshot.php twice daily'
        );

        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/template.logrotate.pmss',
            [
                '/var/log/pmss/storage-health.jsonl /var/log/pmss/storageHealthSnapshot.log',
                'daily',
                'rotate 3650',
                'compress',
                'delaycompress',
                'create 0600 root root',
            ],
            'logrotate policy is missing: '
        );
    }
}
