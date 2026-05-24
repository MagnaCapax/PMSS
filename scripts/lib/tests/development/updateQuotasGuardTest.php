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
        $src = (string) file_get_contents(__DIR__.'/../../../cron/updateQuotas.php');
        $this->assertStringContainsString("pmssListManagedUsers('/scripts/listUsers.php')", $src, 'updateQuotas.php must use the shared listUsers helper');
        foreach ([
            '$thisUser = trim($thisUser);' => 'updateQuotas.php should avoid redundant in-loop trimming after prefiltering',
            "if (\$thisUser === '') {" => 'updateQuotas.php should avoid redundant empty-user guards after prefiltering',
            "shell_exec('/scripts/listUsers.php')" => 'updateQuotas.php should not shell out to listUsers.php inline',
        ] as $needle => $message) {
            $this->pmssAssertStringNotContainsString($needle, $src, $message);
        }

        // Quota handling must be split into safe PHP filesystem operations and a
        // single, quoted quota invocation. Snapshot writes must use the shared
        // same-dir replacer so refreshes never leave ~/.quota missing.
        $this->assertStringContainsAllStrings([
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
        ], $src, 'updateQuotas.php should keep quota snapshot guard: ');
        foreach ([
            'strpos($realHome, $expectedHome) !== 0' => 'updateQuotas.php should not treat prefix-only home-path matches as safe',
            'unlink($quotaFile)' => 'updateQuotas.php must not delete existing quota snapshot before validating a refresh',
            'file_put_contents($quotaFile' => 'updateQuotas.php should not stream quota snapshots directly into the live path',
        ] as $needle => $message) {
            $this->pmssAssertStringNotContainsString($needle, $src, $message);
        }

        // The quota binary itself must be invoked once with a safely quoted username.
        $this->assertStringContainsString("'quota -u '.escapeshellarg(\$thisUser).' -v -s 2>&1'", $src, 'updateQuotas.php must call quota with verbose human-readable output for a single, quoted username');
        $this->assertStringContainsString('escapeshellarg($thisUser)', $src, 'updateQuotas.php must quote usernames in quota command');
    }
}
