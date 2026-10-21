<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

require_once dirname(__DIR__, 4).'/scripts/util/checkUserHtpasswd.php';

class CheckUserHtpasswdTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-check-user-htpasswd-']; }

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

    public function testAppendOwnsTheAccountFileWithoutExtraMetadataCommand(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/checkUserHtpasswd.php');
        $this->assertStringContainsString('pmssAppendAccountFile($thisUser, "/home/{$thisUser}", $userHtpasswd, $thisPassword."\\n", 0640)', $source);
        $this->assertStringContainsString('pmssCheckUserHtpasswdLegacyAppend(', $source);
        $this->assertFalse(strpos($source, 'chown_htpasswd') !== false);
    }

    public function testLegacyAppendRunsUnderAccountAndRejectsLinkedParent(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new SkipTest('Root-only account fixture');
        }
        $account = posix_getpwnam('nobody');
        if (!is_array($account)) throw new SkipTest('Fixture account unavailable');
        $home = $this->pmssEnsureDir($this->tempDir.'/nobody');
        chown($home, $account['uid']);
        $lighttpd = $this->pmssEnsureDir($home.'/.lighttpd');
        chown($lighttpd, $account['uid']);
        $path = $this->pmssWriteFile($lighttpd.'/.htpasswd', "old:hash\n");
        chgrp($path, $account['gid']);
        chmod($path, 0640);
        clearstatcache(true);
        $this->pmssWithEnv(['PMSS_HOME_DIR' => $this->tempDir], function () use ($path): void {
            $this->assertTrue(\pmssCheckUserHtpasswdHasUserEntry($path, 'old'));
        });
        $this->assertTrue(\pmssCheckUserHtpasswdLegacyAppend('nobody', $home, $path, "new:hash\n"));
        $this->assertSame("old:hash\nnew:hash\n", file_get_contents($path));
        $this->assertSame($account['uid'], fileowner($path));

        $this->pmssWriteFile($path, str_repeat('x', 65537));
        chgrp($path, $account['gid']);
        chmod($path, 0640);
        $this->assertFalse(\pmssCheckUserHtpasswdLegacyAppend('nobody', $home, $path, "new:hash\n"));
        $this->assertSame(65537, filesize($path));

        $outside = $this->pmssEnsureDir($this->tempDir.'/root-only');
        $outsidePath = $this->pmssWriteFile($outside.'/.htpasswd', "protected\n");
        chmod($outside, 0700);
        $this->assertTrue(rename($lighttpd, $home.'/.lighttpd-real'));
        symlink($outside, $lighttpd);
        $this->assertFalse(\pmssCheckUserHtpasswdLegacyAppend('nobody', $home, $path, "new:hash\n"));
        $this->pmssWithEnv(['PMSS_HOME_DIR' => $this->tempDir], function () use ($path): void {
            $this->assertSame(null, \pmssCheckUserHtpasswdHasUserEntry($path, 'nobody'));
        });
        $this->assertSame("protected\n", file_get_contents($outsidePath));
    }
}
