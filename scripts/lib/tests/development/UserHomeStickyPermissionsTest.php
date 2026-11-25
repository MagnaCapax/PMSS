<?php
/** Verify sticky home permission convergence using private filesystem fixtures. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserHomeStickyPermissionsTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array
    {
        return ['tempDir', 'pmss-home-sticky-'];
    }

    /** Use the actual policy and applier; no provisioned account home is touched. */
    public function testHomeStickyModeSurvivesConvergenceWithoutChangingChildren(): void
    {
        require_once $this->pmssRepoPath('scripts/lib/user/permissionsPolicy.php');
        require_once $this->pmssRepoPath('scripts/lib/user/permissionsApply.php');
        require_once $this->pmssRepoPath('scripts/lib/shell.php');

        [$modes] = \pmssUserPermissionsPolicy('fixtur01',
            array_fill_keys(['normal', 'local', 'ingress', 'ingressLocal'], '/fixture/unused'));
        $homePolicy = $modes[0];
        $this->assertSame(['/home/fixtur01', 01770], $homePolicy);

        $home = $this->pmssEnsureDir($this->tempDir.'/home/fixtur01');
        $private = $this->pmssEnsureDir($home.'/private');
        $file = $private.'/state';
        $this->pmssWriteFile($file, 'fixture state');
        $this->assertTrue(chmod($private, 0700));
        $this->assertTrue(chmod($file, 0600));

        // Cover legacy homes, an already fixed home, stricter modes and excess access.
        foreach ([0770, 01770, 0700, 0750, 0777] as $initial) {
            $this->assertTrue(chmod($home, $initial));
            for ($pass = 0; $pass < 2; ++$pass) {
                \pmssUserPermissionsModeApply(
                    $this->pmssCurrentOwner(), $home, posix_geteuid(), $home, $homePolicy[1]
                );
                clearstatcache();
                $this->assertSame(01770, fileperms($home) & 07777, sprintf('initial=%04o pass=%d', $initial, $pass));
                $this->assertSame(0700, fileperms($private) & 07777, 'home repair is not recursive');
                $this->assertSame(0600, fileperms($file) & 07777, 'private state stays private');
            }
        }
    }
}
