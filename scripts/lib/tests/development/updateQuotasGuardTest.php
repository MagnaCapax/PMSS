<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateQuotasGuardTest extends TestCase
{
    public function testUpdateQuotasSkipsEmptyAndInvalidUsers(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../cron/updateQuotas.php');
        $this->assertStringContainsString('$thisUser = trim($thisUser);', $src, 'updateQuotas.php must trim usernames in the loop');
        $this->assertStringContainsString('if ($thisUser === \'\') {', $src, 'updateQuotas.php must skip empty usernames');
        $this->assertStringContainsString('!pmssValidateUsername($thisUser)', $src, 'updateQuotas.php must revalidate usernames from listUsers');

        // Quota handling must be split into safe PHP filesystem operations and a
        // single, quoted quota invocation. The .quota file is created and chmodded
        // via PHP rather than through a chained shell command.
        $this->assertStringContainsString('$quotaFile = "/home/{$thisUser}/.quota";', $src, 'updateQuotas.php must derive quota file path from /home/<user>');
        $this->assertStringContainsString('file_exists($quotaFile)', $src, 'updateQuotas.php must check for existing quota file via PHP');
        $this->assertStringContainsString('file_put_contents($quotaFile', $src, 'updateQuotas.php must write quota data via PHP');
        $this->assertStringContainsString('chmod($quotaFile', $src, 'updateQuotas.php must set quota file mode via PHP');
        $this->assertTrue(strpos($src, 'unlink($quotaFile)') === false, 'updateQuotas.php must not delete existing quota snapshot before validating a refresh');
        $this->assertStringContainsString("strpos(\$existingQuotaContent, 'Disk quotas') !== false", $src, 'updateQuotas.php should detect an existing parseable quota snapshot');
        $this->assertStringContainsString('Disk quotas for user {$thisUser} (uid 0):', $src, 'updateQuotas.php should emit a parseable fallback quota header on refresh failures');
        $this->assertStringContainsString('/dev/null      0K      0K      0K', $src, 'updateQuotas.php fallback snapshot should provide numeric quota fields with unit suffixes');

        // The quota binary itself must be invoked once with a safely quoted username.
        $this->assertStringContainsString("'quota -u '.escapeshellarg(\$thisUser).' -s 2>&1'", $src, 'updateQuotas.php must call quota with a single, quoted username');
        $this->assertStringContainsString('escapeshellarg($thisUser)', $src, 'updateQuotas.php must quote usernames in quota command');
    }
}
