<?php
declare(strict_types=1);

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/homeReclaim.php';
require_once dirname(__DIR__, 2).'/user/terminationCleanup.php';

final class TerminateUserContractTest extends TestCase
{
    public function testTerminateUserConfirmationLoopHandlesEof(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/terminateUser.php',
            ['confirmation input unavailable (EOF)', 'Unable to read confirmation input (EOF)'],
            'terminateUser.php should handle EOF confirmation: '
        );
    }

    public function testTerminateUserInvokesSystemdRevertOnSlice(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/terminateUser.php',
            'systemctl revert',
            'terminateUser.php should revert user slice properties'
        );
    }

    public function testTerminateUserClearsCrontabBeforeUserdel(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            ["'crontab_remove'", "'userdel_initial'"],
            'terminateUser.php should define step ',
            'terminateUser.php should clear crontab before deleting the user account: '
        );
    }

    public function testTerminateUserClearsImmutableTrafficBeforeHomeReclaim(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            ["'clear_immutable_traffic'", '$homeReclaimPath = pmssTerminateUserMoveHomeForReclaim'],
            'terminateUser.php should define step ',
            'terminateUser.php should clear immutable traffic files before moving the home aside: '
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/terminateUser.php',
            ['command -v chattr', 'array_values(pmssTrafficDataPaths($username))'],
            'terminateUser.php should keep immutable traffic handling: '
        );
    }

    public function testTerminateUserQueuesHomeReclaimAfterTrafficImmutableCleanup(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            ["'clear_immutable_traffic'", '$homeReclaimPath = pmssTerminateUserMoveHomeForReclaim', "'queue_home_reclaim'"],
            'terminateUser.php should define step ',
            'terminateUser.php should queue home reclaim after traffic immutability cleanup: '
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/user/homeReclaim.php',
            [
                'PMSS_USER_HOME_RECLAIM_LOG',
                'ionice -c3 nice -n 19',
                '/scripts/util/userHomeReclaim.php',
            ],
            'home reclaim should run asynchronously at low priority: '
        );
    }

    public function testTerminateUserReclaimsExactRecreateBackupDir(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/lib/user/terminationCleanup.php',
            [
                'function pmssTerminateUserMoveBackupForReclaim',
                'is_link($backupPath)',
                '$realBackup !== $backupPath',
                "'reclaim_user_backup_dir'",
            ],
            [
                '/home/backup-*',
                'glob(',
            ]
        );
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            [
                '$homeReclaimPath = pmssTerminateUserMoveHomeForReclaim',
                '$backupReclaimPath = pmssTerminateUserMoveBackupForReclaim',
                "'queue_user_backup_reclaim'",
                'pmssUserHomeReclaimLaunchCommand($backupReclaimPath)',
                'pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);',
            ],
            'terminateUser.php should define backup reclaim flow: ',
            'terminateUser.php should queue backup reclaim before nginx cleanup: '
        );
    }

    public function testHomeReclaimPathContract(): void
    {
        $path = \pmssUserHomeReclaimPathBuild('user1234', 1767225600, 42);
        $this->assertSame('/home/.terminating-user1234-20260101000000-42', $path);
        $this->assertSame('user1234', \pmssUserHomeReclaimPathUsername($path));
        $this->assertTrue(\pmssUserHomeReclaimPathIsSafe($path), 'unused generated reclaim path should be safe');
        $this->assertFalse(\pmssUserHomeReclaimPathIsSafe('/home/user1234'), 'active home path must not be reclaimable');
        $this->assertFalse(\pmssUserHomeReclaimPathIsSafe('/tmp/.terminating-user1234-20260101000000-42'), 'reclaim path must stay under /home');
    }

    public function testHomeReclaimLaunchRejectsUnsafePath(): void
    {
        $this->assertSame('', \pmssUserHomeReclaimLaunchCommand('/home/user1234'));

        $command = \pmssUserHomeReclaimLaunchCommand('/home/.terminating-user1234-20260101000000-42');
        $this->assertStringContainsString('/scripts/util/userHomeReclaim.php', $command);
        $this->assertStringContainsString('ionice -c3 nice -n 19', $command);
    }

    public function testTerminateUserHomeInvariantIsExact(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/terminateUser.php',
            [
                '$realHome !== $expectedHome',
                'Prefix checks are too loose',
            ],
            'terminateUser.php should reject prefix-confusable home paths: '
        );
    }

    public function testTerminateUserHandlesUnreadableRtorrentConfig(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/terminateUser.php',
            [
                '$configLines = @file($portFile',
                'cleanup_ports_config_read',
                '$configLines = array();',
            ],
            'terminateUser.php should not parse unreadable rTorrent config as port data: '
        );
    }

    public function testTerminateUserFinalCleanupDoesNotDeleteActiveHomeName(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/terminateUser.php',
            ["'remove_nginx_user'"],
            [
                'escapeshellarg("/home/{$username}")',
            ]
        );
    }

    public function testTerminateUserRemovesNginxSubdomainRouteFiles(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/user/terminationCleanup.php',
            [
                'function pmssTerminateUserRemoveNginxRouteFiles',
                'remove_nginx_route_file',
                'remove_nginx_route_file_hash',
                '"/etc/nginx/conf.d/pmss-user-{$username}{$suffix}.conf"',
            ],
            'terminateUser.php should remove lifecycle-owned nginx conf.d route files: '
        );
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            ['pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);', 'pmssUserLifecycleRefreshNginxConfig('],
            'terminateUser.php should remove stale route files before nginx reload: ',
            'terminateUser.php should reload nginx after route file cleanup: '
        );
    }

    public function testTerminateUserDryRunGuardsDirectCleanupMutations(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/lib/user/terminationCleanup.php',
            [
                'function pmssTerminateUserUnlinkPath',
                'function pmssTerminateUserRemoveEmptyDir',
                "'SKIP'",
                'Dry run; file not removed',
                'Dry run; directory not removed',
            ],
            [
                '@unlink("/etc/nginx/users/{$username}")',
                'unlink($filePath)',
                'rmdir($portsBase)',
            ]
        );
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/terminateUser.php',
            [
                "pmssTerminateUserUnlinkPath(\$username, 'remove_nginx_user_file'",
                'pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);',
                '} elseif ($dryRun) {',
                "'status'  => 'SKIP'",
            ]
        );
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/terminateUser.php',
            ['} elseif ($dryRun) {', '$db->removeUser($username);'],
            'terminateUser.php should guard DB removal: ',
            'terminateUser.php should check dry-run before DB removal: '
        );
    }

    public function testTerminationCleanupHelpersPreserveDryRunAndRemovalContracts(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-terminate-cleanup-');
        $file = $dir.'/route.conf';
        $emptyDir = $dir.'/empty';
        $backupDir = $dir.'/backup-user1234';
        $this->pmssWriteFile($file, 'managed');
        $this->pmssEnsureDir($emptyDir);
        $this->pmssEnsureDir($backupDir);

        $this->assertTrue(\pmssTerminateUserUnlinkPath('user1234', 'remove_file', $file, true));
        $this->assertTrue(is_file($file), 'dry-run unlink must not remove file');
        $this->assertTrue(\pmssTerminateUserUnlinkPath('user1234', 'remove_file', $file, false));
        $this->assertFalse(file_exists($file), 'non-dry unlink should remove file');

        $this->assertTrue(\pmssTerminateUserRemoveEmptyDir('user1234', 'remove_dir', $emptyDir, true));
        $this->assertTrue(is_dir($emptyDir), 'dry-run rmdir must not remove directory');
        $this->assertTrue(\pmssTerminateUserRemoveEmptyDir('user1234', 'remove_dir', $emptyDir, false));
        $this->assertFalse(is_dir($emptyDir), 'non-dry rmdir should remove empty directory');

        $target = \pmssTerminateUserMoveBackupForReclaim('user1234', $backupDir, true);
        $this->assertStringContainsString('/home/.terminating-user1234-', $target);
        $this->assertTrue(is_dir($backupDir), 'dry-run backup reclaim must not move source');
    }
}
