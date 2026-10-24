<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/permissionsApply.php';
require_once dirname(__DIR__, 2).'/shell.php';
require_once __DIR__.'/../common/TestCase.php';

/** userPermissions repairs act on the entry they checked, never on a renamed or linked replacement. */
class UserPermissionsApplyTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-user-permissions-apply-']; }

    protected function setUp(): void
    {
        parent::setUp();
        unset($GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'], $GLOBALS['PMSS_USER_PERMISSIONS_FIRST_FAILED_COMMAND']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'], $GLOBALS['PMSS_USER_PERMISSIONS_FIRST_FAILED_COMMAND']);
        parent::tearDown();
    }

    private function home(): string
    {
        return $this->pmssEnsureDir($this->tempDir.'/home');
    }

    private function file(string $path, int $mode, string $content = 'kept'): string
    {
        $this->pmssEnsureDir(dirname($path));
        $this->pmssWriteFile($path, $content);
        $this->assertTrue(chmod($path, $mode));
        return $path;
    }

    private function mode(string $path): int
    {
        clearstatcache(true, $path);
        return fileperms($path) & 07777;
    }

    private function mtime(string $path): int
    {
        clearstatcache(true, $path);
        return lstat($path)['mtime'];
    }

    public function testAccountOwnedTreeIsNormalisedWithoutFollowingLinks(): void
    {
        $home = $this->home();
        $outside = $this->file($this->tempDir.'/outside/state', 0600);
        $config = $this->pmssEnsureDir($home.'/.config');
        $this->assertTrue(chmod($config, 0700));
        $file = $this->file($config.'/app/settings', 0600);
        $this->pmssCreateSymlinkOrSkip($outside, $config.'/linked');
        $this->pmssCreateSymlinkOrSkip(dirname($outside), $config.'/linkeddir');
        $outsideDirMode = $this->mode(dirname($outside));

        \pmssUserPermissionsModeApply($this->pmssCurrentOwner(), $home, posix_geteuid(), $config, 0770, true);

        $this->assertSame(0770, $this->mode($config));
        $this->assertSame(0770, $this->mode($config.'/app'));
        $this->assertSame(0770, $this->mode($file));
        $this->assertSame(0600, $this->mode($outside));
        $this->assertSame($outsideDirMode, $this->mode(dirname($outside)));
        $this->assertTrue(is_link($config.'/linked'));
        $this->assertSame(0, $GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'] ?? 0);
    }

    public function testModeChangesRunOnlyAsTheAccountThatOwnsTheHome(): void
    {
        $other = function_exists('posix_getpwnam') ? @posix_getpwnam('nobody') : false;
        if (!is_array($other) || $other['uid'] === posix_geteuid()) {
            throw new SkipTest('no second account to compare with');
        }
        $home = $this->home();
        $file = $this->file($home.'/.viminfo', 0600);

        \pmssUserPermissionsModeApply('nobody', $home, $other['uid'], $file, 0640);

        // Only root or that account may start the change; neither holds here, so nothing runs and the miss is reported.
        $this->assertSame(0600, $this->mode($file));
        $this->assertSame(1, $GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'] ?? 0);
        $this->assertStringContainsString('(as nobody)', $GLOBALS['PMSS_USER_PERMISSIONS_FIRST_FAILED_COMMAND']);
    }

    public function testFinalLinkAndLinkedDirectoryLeaveOutsideFilesUnchanged(): void
    {
        $home = $this->home();
        $outside = $this->file($this->tempDir.'/outside/rutorrent/conf/config.php', 0600);
        $this->pmssCreateSymlinkOrSkip($outside, $home.'/.viminfo');
        $this->pmssCreateSymlinkOrSkip($this->tempDir.'/outside', $home.'/www');
        $outsideDirMode = $this->mode($this->tempDir.'/outside');

        foreach ([[$home.'/.viminfo', 0640], [$home.'/www/rutorrent/conf/config.php', 0754], [$home.'/www', 0750]] as [$path, $mode]) {
            \pmssUserPermissionsModeApply($this->pmssCurrentOwner(), $home, posix_geteuid(), $path, $mode);
            \pmssUserPermissionsModeApply($this->pmssCurrentOwner(), $home, posix_geteuid(), $path, $mode, true);
        }

        $this->assertSame(0600, $this->mode($outside));
        $this->assertSame($outsideDirMode, $this->mode($this->tempDir.'/outside'));
    }

    public function testOpenedFileModeAppliesOnlyToSingleLinkFilesInsideHome(): void
    {
        $home = $this->home();
        $uid = posix_geteuid();
        $owner = $this->pmssCurrentOwner();
        $marker = $this->file($home.'/.quota', 0600, "100\n");
        $outside = $this->file($this->tempDir.'/outside/conf/config.php', 0600);
        $this->pmssCreateSymlinkOrSkip($this->tempDir.'/outside/conf', $home.'/conf');
        $this->pmssCreateSymlinkOrSkip($outside, $home.'/.notifyEmail');
        $this->assertTrue(link($outside, $home.'/.billingId'));

        $this->assertTrue(\pmssUserPermissionsOpenedModeSet($owner, $home, $marker, 0640, $uid));
        $this->assertSame(0640, $this->mode($marker));
        $this->assertSame("100\n", file_get_contents($marker));
        $this->assertFalse(\pmssUserPermissionsOpenedModeSet($owner, $home, $marker, 0600, $uid + 1));
        $this->assertSame(0640, $this->mode($marker));
        foreach (['/conf/config.php', '/.notifyEmail', '/.billingId', '/missing'] as $name) {
            $this->assertFalse(\pmssUserPermissionsOpenedModeSet($owner, $home, $home.$name, 0644, $uid), $name);
        }
        $this->assertSame(0600, $this->mode($outside));
    }

    public function testOpenedHandleOutsideHomeIsRefusedWhateverTheNameSays(): void
    {
        $home = $this->home();
        $uid = posix_geteuid();
        $outside = $this->file($this->tempDir.'/outside/conf/config.php', 0600);
        $this->pmssCreateSymlinkOrSkip($this->tempDir.'/outside/conf', $home.'/conf');
        $inside = $this->file($home.'/.rtorrent.rc', 0600);

        // Open through the linked directory, as a find-then-open sequence would after a swap.
        $handle = fopen($home.'/conf/config.php', 'rb');
        $this->assertTrue(is_resource($handle));
        try {
            $this->assertSame(null, \pmssUserPermissionsHandleInHome($handle, $home, $uid));
        } finally {
            fclose($handle);
        }

        $handle = fopen($inside, 'rb');
        try {
            $opened = \pmssUserPermissionsHandleInHome($handle, $home, $uid);
            $this->assertTrue(is_string($opened) && \pmssUserFileOpenedPathIs($opened));
            $this->assertSame(null, \pmssUserPermissionsHandleInHome($handle, $home, $uid + 1));
            $this->assertSame(null, \pmssUserPermissionsHandleInHome($handle, $this->tempDir.'/outside', $uid));
        } finally {
            fclose($handle);
        }
        $this->assertSame(0600, $this->mode($outside));
    }

    public function testAnchoredFindReachesOnlyTheTargetThroughRealDirectories(): void
    {
        $home = $this->home();
        $target = $this->file($home.'/www/rutorrent/conf/config.php', 0644);
        $sibling = $this->file($home.'/www/rutorrent/conf/other.php', 0644);
        $outside = $this->file($this->tempDir.'/outside/rutorrent/conf/config.php', 0644);
        $outsideTree = $this->file($this->tempDir.'/outside/tree/file', 0644);
        $this->pmssCreateSymlinkOrSkip($this->tempDir.'/outside', $home.'/alt');
        $this->pmssCreateSymlinkOrSkip($outside, $home.'/www/rutorrent/conf/linked.php');
        $this->pmssCreateSymlinkOrSkip($this->tempDir.'/outside/tree', $home.'/.lighttpd');
        $treeFile = $this->file($home.'/.lighttpdreal/a/b', 0644);
        $this->pmssCreateSymlinkOrSkip($this->tempDir.'/outside', $home.'/.lighttpdreal/a/up');
        $before = [$sibling => $this->mtime($sibling), $outside => $this->mtime($outside), $outsideTree => $this->mtime($outsideTree)];

        $tests = '-not -type l \( ! -type f -o -links 1 \)';
        $action = '-execdir touch -h -d @0 {} +';
        foreach ([
            [$home.'/www/rutorrent/conf/config.php', false],
            [$home.'/alt/rutorrent/conf/config.php', false],
            [$home.'/www/rutorrent/conf/linked.php', false],
            [$home.'/.lighttpd', true],
            [$home.'/.lighttpdreal/', true],
        ] as [$path, $recursive]) {
            $command = \pmssUserPermissionsAnchoredFind($home, $path, $recursive, $tests, $action);
            $this->assertTrue(is_string($command), $path);
            $this->assertSame(0, \pmssRun($command), $command);
        }

        $this->assertSame(0, $this->mtime($target));
        $this->assertSame(0, $this->mtime($treeFile));
        $this->assertSame(0, $this->mtime($home.'/.lighttpdreal/a'));
        foreach ($before as $path => $mtime) {
            $this->assertSame($mtime, $this->mtime($path), $path);
        }
        $this->assertTrue(is_link($home.'/alt') && is_link($home.'/.lighttpd'));
    }

    public function testAnchoredFindRejectsPathsItCannotPlace(): void
    {
        $home = $this->home();
        foreach ([$this->tempDir.'/elsewhere', $home.'/../x', $home.'/a/./b', $home.'//a', $home.'x/a', "{$home}/a\0b"] as $path) {
            $this->assertSame(null, \pmssUserPermissionsAnchoredFind($home, $path, false, '-true', '-true'), $path);
        }
        $this->assertStringContainsString(' -maxdepth 0 ', (string) \pmssUserPermissionsAnchoredFind($home, $home.'/', false, '-true', '-true'));
    }

    public function testOwnerApplyRunsChownInsideTheHeldDirectory(): void
    {
        $home = $this->home();
        $file = $this->file($home.'/.lighttpd/.htpasswd', 0640);
        $spec = posix_geteuid().':'.posix_getegid();

        \pmssUserPermissionsOwnerApply($home, $file, $spec);
        \pmssUserPermissionsOwnerApply($home, $home.'/.lighttpd', $spec, '-true', true);
        \pmssUserPermissionsOwnerApply($home, $home, $spec);
        \pmssUserPermissionsOwnerApply($home, $home.'/missing/entry', $spec);

        $this->assertSame(0, $GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'] ?? 0);
        $this->assertSame(posix_geteuid(), fileowner($file));
    }

    public function testOpenedGroupSetNeverAdoptsAFileOwnedBySomeoneElse(): void
    {
        $home = $this->home();
        $uid = posix_geteuid();
        $owner = $this->pmssCurrentOwner();
        $group = posix_getgrgid(posix_getegid());
        if (!is_array($group)) {
            throw new SkipTest('current group has no name');
        }
        $file = $this->file($home.'/.notifyEmail', 0640, "elsewhere@example.test\n");
        $outside = $this->file($this->tempDir.'/outside/.quota', 0600);
        $this->pmssCreateSymlinkOrSkip($outside, $home.'/.quota');

        // A file swapped in after the lstat gate has the wrong owner; nothing changes on it.
        $this->assertFalse(\pmssUserPermissionsOpenedGroupSet($owner, $home, $file, $group['name'], $uid + 1));
        $this->assertSame($uid, fileowner($file));
        $this->assertSame(0, $GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'] ?? 0);
        foreach (['/.quota', '/missing'] as $name) {
            $this->assertFalse(\pmssUserPermissionsOpenedGroupSet($owner, $home, $home.$name, $group['name'], $uid), $name);
        }

        // The expected owner gets its group set through the opened inode; content and owner stay.
        $this->assertTrue(\pmssUserPermissionsOpenedGroupSet($owner, $home, $file, $group['name'], $uid));
        $this->assertSame($uid, fileowner($file));
        $this->assertSame(posix_getegid(), filegroup($file));
        $this->assertSame("elsewhere@example.test\n", file_get_contents($file));
    }

    public function testUserPermissionsRoutesEveryRepairThroughTheCheckedObject(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/userPermissions.php', [
            'required' => [
                "require_once __DIR__.'/../lib/user/permissionsApply.php';",
                'pmssUserPermissionsModeApply($thisUser, $homeDir, $accountUid, $path, $perm, $recursive);',
                "pmssUserPermissionsOwnerApply(\$homeDir, \$path, \$owner, \$predicate, \$predicate !== '');",
                'pmssUserPermissionsAccountRun($thisUser, $homeDir, [$homeDir], sprintf(',
                'pmssUserPermissionsAccountRun($thisUser, $homeDir, [$wwwDir], sprintf(',
            ],
            'forbidden' => [
                'function chmodPath(' => 'Mode changes go through pmssUserPermissionsModeApply',
                'function chownPath(' => 'Owner changes go through pmssUserPermissionsOwnerApply',
                '-exec chown' => 'chown runs through -execdir',
                'pmssUserPermissionsRun(sprintf(' => 'Mode walks run as the account',
            ],
        ]);
        $this->assertOrderedStrings([
            'return @lstat($path) !== false && ($rootArtifactRepairable[$path] ?? true);',
            'pmssTrafficSetImmutable($trafficFile, false);',
            "\$findParts[] = '-execdir chown -h';",
            'pmssUserPermissionsOwnerApply($homeDir, $path, $owner',
            "'find %s \( -path %s -o -type d -not -uid %d \) -prune -o -type d -not -perm 0750 -exec chmod 0750 {} +'",
            "'find %s -type d -not -uid %d -prune -o -type f -links 1 -uid %d -perm /0111 -exec chmod a-x {} +'",
            'pmssUserPermissionsModeApply($thisUser, $homeDir, $accountUid, $path, $perm, $recursive);',
            'pmssTrafficSetImmutable($trafficFile, true);',
        ], $this->pmssReadRepoFile('scripts/util/userPermissions.php'));
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/permissionsApply.php', [
            "pmssUserFileOpenVerified(\$path, 'rbn')",
            '@readlink($procPath)',
            "\$action = '-execdir chown -h '",
            'pmssAccountPathRun($user, $home, $paths, $command, null, 0, $timedOut, $failure)',
        ]);
    }
}
