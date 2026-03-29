<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

define('PMSS_CHECK_USER_HTPASSWD_LIB_ONLY', true);
require_once dirname(__DIR__, 4).'/scripts/util/checkUserHtpasswd.php';

class CheckUserHtpasswdTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-check-user-htpasswd-');
    }

    protected function tearDown(): void
    {
        $this->pmssCleanupTempDirProperty('tempDir');
    }

    public function testMissingFileReturnsFalse(): void
    {
        $path = $this->tempDir.'/missing.htpasswd';

        $this->assertFalse(\pmssCheckUserHtpasswdHasUserEntry($path, 'alice') === true);
    }

    public function testMatchingUserReturnsTrue(): void
    {
        $path = $this->tempDir.'/user.htpasswd';
        file_put_contents($path, "alice:hash\n");

        $this->assertTrue(\pmssCheckUserHtpasswdHasUserEntry($path, 'alice') === true);
    }

    public function testAbsentUserReturnsFalse(): void
    {
        $path = $this->tempDir.'/user.htpasswd';
        file_put_contents($path, "bob:hash\n");

        $this->assertFalse(\pmssCheckUserHtpasswdHasUserEntry($path, 'alice') === true);
    }

    public function testEmptyUsernameReturnsFalseWithoutTreatingEveryFileAsMatched(): void
    {
        $path = $this->tempDir.'/user.htpasswd';
        file_put_contents($path, "alice:hash\n");

        $this->assertFalse(\pmssCheckUserHtpasswdHasUserEntry($path, '') === true);
    }

    public function testUnsafeUsernameReturnsFalseBeforeReadingContents(): void
    {
        $path = $this->tempDir.'/user.htpasswd';
        file_put_contents($path, "alice:hash\n");

        $this->assertFalse(\pmssCheckUserHtpasswdHasUserEntry($path, '../alice') === true);
    }

    public function testEmptyFileReturnsFalse(): void
    {
        $path = $this->tempDir.'/empty.htpasswd';
        file_put_contents($path, '');

        $this->assertFalse(\pmssCheckUserHtpasswdHasUserEntry($path, 'alice') === true);
    }

    public function testUnreadableFileReturnsNullWhenReadFails(): void
    {
        $path = $this->tempDir.'/locked.htpasswd';
        file_put_contents($path, "alice:hash\n");
        chmod($path, 0000);

        $result = \pmssCheckUserHtpasswdHasUserEntry($path, 'alice');

        chmod($path, 0600);
        if ($result !== null && $result !== true) {
            $this->fail('Unreadable htpasswd should return null when file reads fail');
        }
        if ($result === true && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $this->fail('Unreadable htpasswd unexpectedly remained readable');
        }
    }

    public function testSourceKeepsOptionalLoggingGuardAndReadErrorMessage(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 4).'/scripts/util/checkUserHtpasswd.php');

        $this->assertStringContainsString("pmssUserLifecycleContextLogStatusMessage('htpasswd'", $src);
        $this->assertStringContainsString('Unable to read per-user htpasswd; skipping synchronization', $src);
        $this->assertStringContainsString('Skipping htpasswd sync for invalid username', $src);
    }
}
