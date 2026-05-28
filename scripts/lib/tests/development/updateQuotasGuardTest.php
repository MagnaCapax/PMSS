<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateQuotasGuardTest extends TestCase
{
    public function testRootCronGuardsUpdateQuotasAgainstOverlap(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/seedbox/config/root.cron',
            ['* * * * *', 'flock -xn /tmp/pmss-updateQuotas.lock', '/scripts/cron/updateQuotas.php'],
            'root.cron should guard updateQuotas with flock: '
        );
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
                'file_exists($quotaFile)',
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
}
