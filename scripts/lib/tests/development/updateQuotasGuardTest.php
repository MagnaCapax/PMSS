<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateQuotasGuardTest extends TestCase
{
    public function testCronJobsRetainSharedNonBlockingLocks(): void
    {
        // All six migrated jobs keep their original names and stderr skip policy (ADR 0049).
        foreach (['updateQuotas', 'checkRootlessDocker', 'trafficIngressLog', 'trafficIngressStats', 'trafficLimits', 'trafficStats'] as $name) {
            $this->pmssAssertRepoFileContainsString('scripts/cron/'.$name.'.php', '$'.$name.'Lock = pmssCronLockAcquire(\''.$name.'\');');
        }
    }

    public function testUpdateQuotasSkipsEmptyAndInvalidUsers(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/cron/updateQuotas.php', "pmssListManagedUsers('/scripts/listUsers.php')", 'updateQuotas.php must use the shared listUsers helper');
        $this->pmssAssertRepoFileNotContainsStrings(
            'scripts/cron/updateQuotas.php',
            ['$thisUser = trim($thisUser);', "if (\$thisUser === '') {", "shell_exec('/scripts/listUsers.php')"],
            'updateQuotas.php should not keep redundant user filtering: '
        );

        // Quota handling must be split into safe PHP filesystem operations and a
        // single, quoted quota invocation. Snapshot writes must use the shared
        // same-dir replacer so refreshes never leave ~/.quota missing.
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/updateQuotas.php',
            [
                '$quotaFile = "/home/{$thisUser}/.quota";',
                "pmssReadRegularFileContents(\$quotaFile) ?? ''",
                'function pmssQuotaSnapshotWrite',
                "require_once __DIR__.'/../lib/quotaSnapshot.php';",
                "require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';",
                '$realHome === false || $realHome !== $expectedHome',
                'pmssReplaceUserFilePreservingMetadata($path, $content',
                'pmssQuotaSnapshotWrite($quotaFile, $fallbackContent)',
                'pmssQuotaSnapshotWrite($quotaFile, $content)',
                'pmssQuotaSnapshotNormalizeHumanReadableOutput($content)',
                "strpos(\$existingQuotaContent, 'Disk quotas') !== false",
                'Disk quotas for user {$thisUser} (uid 0):',
                '/dev/null      0K      0K      0K',
            ],
            'updateQuotas.php should keep quota snapshot guard: '
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            'scripts/cron/updateQuotas.php',
            ['strpos($realHome, $expectedHome) !== 0', 'unlink($quotaFile)', 'file_put_contents($quotaFile'],
            'updateQuotas.php should keep safe quota snapshot writes, not: '
        );

        // The quota binary itself must be invoked once with a safely quoted username.
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/cron/updateQuotas.php',
            ["'quota -u '.escapeshellarg(\$thisUser).' -v -s 2>&1'", 'escapeshellarg($thisUser)'],
            'updateQuotas.php should keep quoted quota command: '
        );
    }

    public function testPreviousSnapshotReadRejectsSpecialFilesAndPreservesBytes(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-quota-read-');
        $content = "Disk quotas for user sample (uid 1000):\r\n  /dev/example 1K 2K 3K\n";
        file_put_contents($directory.'/regular', $content);
        file_put_contents($directory.'/empty', '');
        $this->assertTrue(symlink($directory.'/regular', $directory.'/link'));
        $this->assertTrue(symlink($directory.'/missing', $directory.'/dangling'));
        $this->assertTrue(posix_mkfifo($directory.'/fifo', 0600));

        // Execute only the actual snapshot read/recognition block, never cron
        // setup or quota commands. Bound the child so a FIFO regression fails.
        $source = file_get_contents($this->pmssRepoPath('scripts/cron/updateQuotas.php'));
        $this->assertSame(1, preg_match('/    \$existingQuotaContent = .*?;\n    \$hasExistingSnapshot = .*?;/s', $source, $matches));
        $paths = [$directory.'/regular', $directory.'/empty', $directory.'/missing',
            $directory, $directory.'/link', $directory.'/dangling', $directory.'/fifo'];
        $script = 'require '.var_export($this->pmssRepoPath('scripts/lib/runtime.php'), true).';'
            .'$results = []; foreach ('.var_export($paths, true).' as $quotaFile) {'
            .$matches[0].'$results[] = [$existingQuotaContent, $hasExistingSnapshot]; }'
            .'echo json_encode($results);';
        $result = $this->pmssExecShellCommand('timeout --kill-after=2s 5s '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));

        $this->assertSame(0, $result['rc'], 'Snapshot reads must finish without opening the FIFO');
        $this->assertSame(array_merge([[$content, true]], array_fill(0, 6, ['', false])), json_decode($result['output'], true));
        $this->assertSame($content, file_get_contents($directory.'/regular'));
        $this->assertSame('fifo', filetype($directory.'/fifo'));
    }
}
