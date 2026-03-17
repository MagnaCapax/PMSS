<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateQuotasGuardTest extends TestCase
{
    public function testUpdateQuotasSkipsEmptyAndInvalidUsers(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../cron/updateQuotas.php');
        $this->assertStringContainsString("array_map('trim', explode(\"\\n\", trim((string) shell_exec('/scripts/listUsers.php'))))", $src, 'updateQuotas.php must trim usernames before the loop');
        $this->assertTrue(strpos($src, '$thisUser = trim($thisUser);') === false, 'updateQuotas.php should avoid redundant in-loop trimming after prefiltering');
        $this->assertTrue(strpos($src, "if (\$thisUser === '') {") === false, 'updateQuotas.php should avoid redundant empty-user guards after prefiltering');
        $this->assertStringContainsString('!pmssValidateUsername($thisUser)', $src, 'updateQuotas.php must revalidate usernames from listUsers');

        // Quota handling must be split into safe PHP filesystem operations and a
        // single, quoted quota invocation. Snapshot writes must use a same-dir
        // temp file plus rename so refreshes never leave ~/.quota missing.
        $this->assertStringContainsString('$quotaFile = "/home/{$thisUser}/.quota";', $src, 'updateQuotas.php must derive quota file path from /home/<user>');
        $this->assertStringContainsString('file_exists($quotaFile)', $src, 'updateQuotas.php must check for existing quota file via PHP');
        $this->assertStringContainsString('function pmssQuotaSnapshotWrite', $src, 'updateQuotas.php should own the atomic snapshot writer locally');
        $this->assertStringContainsString("tempnam(\$dir, basename(\$path).'.pmss-tmp-')", $src, 'updateQuotas.php must stage quota snapshots in the destination directory');
        $this->assertStringContainsString('@rename($tmp, $path)', $src, 'updateQuotas.php must atomically replace quota snapshots via rename');
        $this->assertStringContainsString('pmssQuotaSnapshotWrite($quotaFile, $fallbackContent)', $src, 'updateQuotas.php must atomically write fallback quota snapshots');
        $this->assertStringContainsString('pmssQuotaSnapshotWrite($quotaFile, $content)', $src, 'updateQuotas.php must atomically write refreshed quota snapshots');
        $this->assertTrue(strpos($src, 'unlink($quotaFile)') === false, 'updateQuotas.php must not delete existing quota snapshot before validating a refresh');
        $this->assertTrue(strpos($src, 'file_put_contents($quotaFile') === false, 'updateQuotas.php should not stream quota snapshots directly into the live path');
        $this->assertStringContainsString("strpos(\$existingQuotaContent, 'Disk quotas') !== false", $src, 'updateQuotas.php should detect an existing parseable quota snapshot');
        $this->assertStringContainsString('Disk quotas for user {$thisUser} (uid 0):', $src, 'updateQuotas.php should emit a parseable fallback quota header on refresh failures');
        $this->assertStringContainsString('/dev/null      0K      0K      0K', $src, 'updateQuotas.php fallback snapshot should provide numeric quota fields with unit suffixes');

        // The quota binary itself must be invoked once with a safely quoted username.
        $this->assertStringContainsString("'quota -u '.escapeshellarg(\$thisUser).' -s 2>&1'", $src, 'updateQuotas.php must call quota with a single, quoted username');
        $this->assertStringContainsString('escapeshellarg($thisUser)', $src, 'updateQuotas.php must quote usernames in quota command');
    }
}
