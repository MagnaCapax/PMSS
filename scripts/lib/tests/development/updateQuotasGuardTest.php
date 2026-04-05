<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateQuotasGuardTest extends TestCase
{
    public function testUpdateQuotasSkipsEmptyAndInvalidUsers(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../cron/updateQuotas.php');
        $this->assertStringContainsString("pmssListManagedUsers('/scripts/listUsers.php')", $src, 'updateQuotas.php must use the shared listUsers helper');
        $this->assertTrue(strpos($src, '$thisUser = trim($thisUser);') === false, 'updateQuotas.php should avoid redundant in-loop trimming after prefiltering');
        $this->assertTrue(strpos($src, "if (\$thisUser === '') {") === false, 'updateQuotas.php should avoid redundant empty-user guards after prefiltering');
        $this->assertTrue(strpos($src, "shell_exec('/scripts/listUsers.php')") === false, 'updateQuotas.php should not shell out to listUsers.php inline');

        // Quota handling must be split into safe PHP filesystem operations and a
        // single, quoted quota invocation. Snapshot writes must use the shared
        // same-dir replacer so refreshes never leave ~/.quota missing.
        $this->assertStringContainsString('$quotaFile = "/home/{$thisUser}/.quota";', $src, 'updateQuotas.php must derive quota file path from /home/<user>');
        $this->assertStringContainsString('file_exists($quotaFile)', $src, 'updateQuotas.php must check for existing quota file via PHP');
        $this->assertStringContainsString('function pmssQuotaSnapshotWrite', $src, 'updateQuotas.php should own the atomic snapshot writer locally');
        $this->assertStringContainsString("require_once __DIR__.'/../lib/quotaSnapshot.php';", $src, 'updateQuotas.php should load the quota snapshot normalizer');
        $this->assertStringContainsString("require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';", $src, 'updateQuotas.php should load the shared managed-file writer');
        $this->assertStringContainsString('pmssReplaceUserFile($path, $content', $src, 'updateQuotas.php must stage quota snapshots through the shared replacer');
        $this->assertStringContainsString('pmssQuotaSnapshotWrite($quotaFile, $fallbackContent)', $src, 'updateQuotas.php must atomically write fallback quota snapshots');
        $this->assertStringContainsString('pmssQuotaSnapshotWrite($quotaFile, $content)', $src, 'updateQuotas.php must atomically write refreshed quota snapshots');
        $this->assertStringContainsString('pmssQuotaSnapshotNormalizeHumanReadableOutput($content)', $src, 'updateQuotas.php should normalize human-readable quota snapshots before writing them');
        $this->assertTrue(strpos($src, 'unlink($quotaFile)') === false, 'updateQuotas.php must not delete existing quota snapshot before validating a refresh');
        $this->assertTrue(strpos($src, 'file_put_contents($quotaFile') === false, 'updateQuotas.php should not stream quota snapshots directly into the live path');
        $this->assertStringContainsString("strpos(\$existingQuotaContent, 'Disk quotas') !== false", $src, 'updateQuotas.php should detect an existing parseable quota snapshot');
        $this->assertStringContainsString('Disk quotas for user {$thisUser} (uid 0):', $src, 'updateQuotas.php should emit a parseable fallback quota header on refresh failures');
        $this->assertStringContainsString('/dev/null      0K      0K      0K', $src, 'updateQuotas.php fallback snapshot should provide numeric quota fields with unit suffixes');

        // The quota binary itself must be invoked once with a safely quoted username.
        $this->assertStringContainsString("'quota -u '.escapeshellarg(\$thisUser).' -v -s 2>&1'", $src, 'updateQuotas.php must call quota with verbose human-readable output for a single, quoted username');
        $this->assertStringContainsString('escapeshellarg($thisUser)', $src, 'updateQuotas.php must quote usernames in quota command');
    }
}
