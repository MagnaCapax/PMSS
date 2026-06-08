<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

require_once dirname(__DIR__, 4).'/scripts/util/checkUserHtpasswd.php';

class CheckUserHtpasswdTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-check-user-htpasswd-');
    }

    public function testUserEntryLookupReturnsExpectedResultForSimpleCases(): void
    {
        foreach ([
            'missing file' => ['missing.htpasswd', null, 'alice', false],
            'matching user' => ['matching.htpasswd', "alice:hash\n", 'alice', true],
            'absent user' => ['absent.htpasswd', "bob:hash\n", 'alice', false],
            'empty username' => ['empty-username.htpasswd', "alice:hash\n", '', false],
            'unsafe username' => ['unsafe-username.htpasswd', "alice:hash\n", '../alice', false],
            'empty file' => ['empty.htpasswd', '', 'alice', false],
        ] as $label => $case) {
            $path = $this->tempDir.'/'.$case[0];
            if ($case[1] !== null) {
                file_put_contents($path, $case[1]);
            }

            $this->assertSame($case[3], \pmssCheckUserHtpasswdHasUserEntry($path, $case[2]) === true, $label);
        }
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
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/checkUserHtpasswd.php', [
            "pmssUserLifecycleContextLogStatusMessage('htpasswd'",
            'Unable to read per-user htpasswd; skipping synchronization',
            'Skipping htpasswd sync for invalid username',
        ]);
    }
}
