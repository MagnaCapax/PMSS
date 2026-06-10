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
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', ['required' => ['confirmation input unavailable (EOF)', 'Unable to read confirmation input (EOF)']]);
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
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', ['ordered' => [[
                "'crontab_remove'",
                "'userdel_initial'",
            ]]]);
    }

    public function testTerminateUserClearsImmutableTrafficBeforeHomeReclaim(): void
    {
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', [
                'required' => ['command -v chattr', 'array_values(pmssTrafficDataPaths($username))'],
                'ordered' => [[
                    "'clear_immutable_traffic'",
                    '$homeReclaimPath = pmssTerminateUserMoveHomeForReclaim',
                ]],
            ]);
    }

    public function testTerminateUserQueuesHomeReclaimAfterTrafficImmutableCleanup(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/terminateUser.php' => ['ordered' => [[
                "'clear_immutable_traffic'",
                '$homeReclaimPath = pmssTerminateUserMoveHomeForReclaim',
                "'queue_home_reclaim'",
            ]]],
            'scripts/lib/user/homeReclaim.php' => ['required' => [
                'PMSS_USER_HOME_RECLAIM_LOG',
                'ionice -c3 nice -n 19',
                '/scripts/util/userHomeReclaim.php',
            ]],
        ]);
    }

    public function testTerminateUserReclaimsExactRecreateBackupDir(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/terminationCleanup.php' => [
                'required' => [
                    'function pmssTerminateUserMoveBackupForReclaim',
                    'is_link($backupPath)',
                    '$realBackup !== $backupPath',
                    "'reclaim_user_backup_dir'",
                ],
                'forbidden' => ['/home/backup-*', 'glob('],
            ],
            'scripts/terminateUser.php' => ['ordered' => [[
                '$homeReclaimPath = pmssTerminateUserMoveHomeForReclaim',
                '$backupReclaimPath = pmssTerminateUserMoveBackupForReclaim',
                "'queue_user_backup_reclaim'",
                'pmssUserHomeReclaimLaunchCommand($backupReclaimPath)',
                'pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);',
            ]]],
        ]);
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
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', ['required' => [
                '$realHome !== $expectedHome',
                'Prefix checks are too loose',
            ]]);
    }

    public function testTerminateUserHandlesUnreadableRtorrentConfig(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/userConfig.php' => ['required' => [
                'rtorrentDhtPort',
                'rtorrentListenPort',
                'failed to persist rTorrent ports',
            ]],
            'scripts/terminateUser.php' => ['required' => [
                'pmssTerminateUserReleaseRtorrentPortReservations($username',
            ]],
            'scripts/lib/user/terminationCleanup.php' => ['required' => [
                'function pmssTerminateUserReleaseRtorrentPortReservations',
                'function pmssTerminateUserRtorrentPortRecord',
                'pmssTerminateUserRtorrentStoredPorts',
                '$configLines = @file($portFile',
                'pmssNetworkPortParseDigits',
                'cleanup_ports_config_read',
                'cleanup_ports_config_invalid',
                'rtorrentDhtPort',
                'rtorrentListenPort',
            ]],
        ]);
    }

    public function testTerminateUserFinalCleanupDoesNotDeleteActiveHomeName(): void
    {
        $this->pmssAssertRepoFileContract('scripts/terminateUser.php', [
                'required' => ["'remove_nginx_user'"],
                'forbidden' => ['escapeshellarg("/home/{$username}")'],
            ]);
    }

    public function testTerminateUserRemovesNginxSubdomainRouteFiles(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/terminationCleanup.php' => ['required' => [
                'function pmssTerminateUserRemoveNginxRouteFiles',
                'remove_nginx_route_file',
                'remove_nginx_route_file_hash',
                '"/etc/nginx/conf.d/pmss-user-{$username}{$suffix}.conf"',
            ]],
            'scripts/terminateUser.php' => ['ordered' => [[
                'pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);',
                'pmssUserLifecycleRefreshNginxConfig(',
            ]]],
        ]);
    }

    public function testTerminateUserDryRunGuardsDirectCleanupMutations(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/user/terminationCleanup.php' => [
                'required' => [
                    'function pmssTerminateUserUnlinkPath',
                    'function pmssTerminateUserRemoveEmptyDir',
                    "'SKIP'",
                    'Dry run; file not removed',
                    'Dry run; directory not removed',
                ],
                'forbidden' => [
                    '@unlink("/etc/nginx/users/{$username}")',
                    'unlink($filePath)',
                    'rmdir($portsBase)',
                ],
            ],
            'scripts/terminateUser.php' => [
                'required' => [
                    "pmssTerminateUserUnlinkPath(\$username, 'remove_nginx_user_file'",
                    'pmssTerminateUserRemoveNginxRouteFiles($username, $dryRun);',
                    '} elseif ($dryRun) {',
                    "'status'  => 'SKIP'",
                ],
                'ordered' => [[
                    '} elseif ($dryRun) {',
                    '$db->removeUser($username);',
                ]],
            ],
        ]);
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

    public function testTerminationCleanupHelpersRejectUnsafePathInputs(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-terminate-unsafe-cleanup-');
        $file = $dir.'/route.conf';
        $emptyDir = $dir.'/empty';
        $this->pmssWriteFile($file, 'managed');
        $this->pmssEnsureDir($emptyDir);

        $this->assertFalse(\pmssTerminateUserUnlinkPath('user1234', 'remove_file', "bad\0path", false));
        $this->assertTrue(is_file($file), 'unsafe unlink input must not affect nearby files');
        $this->assertFalse(\pmssTerminateUserRemoveEmptyDir('user1234', 'remove_dir', "bad\0path", false));
        $this->assertTrue(is_dir($emptyDir), 'unsafe rmdir input must not affect nearby dirs');

        $this->assertSame(
            '',
            \pmssTerminateUserMovePathForReclaim(
                'user1234',
                'home_reclaim_rename',
                $dir."\0bad",
                true,
                'Dry run; home not renamed',
                'Failed to rename home for background reclaim',
                'Renamed home for background reclaim'
            )
        );
        $this->assertSame(
            '',
            \pmssTerminateUserMovePathForReclaim(
                'user1234',
                'home_reclaim_rename',
                $dir,
                true,
                'Dry run; home not renamed',
                'Failed to rename home for background reclaim',
                'Renamed home for background reclaim',
                array(),
                true
            )
        );
    }

    public function testTerminateUserRtorrentPortCleanupPreservesDryRunAndRemovesFiles(): void
    {
        $base = $this->pmssMakeTempDir('pmss-terminate-ports-');
        $store = new \UserConfigStore($this->pmssMakeTempDir('pmss-terminate-store-').'/seedbox/config');
        $this->assertTrue($store->set('user1234', [
            'ramMiB' => 512,
            'rtorrentPort' => '5050',
            'rtorrentDhtPort' => '5051',
            'rtorrentListenPort' => '5052',
            'quota' => 10,
            'quotaBurst' => 12,
        ]));
        $config = $this->pmssMakeTempFile('pmss-terminate-ports-config-');
        $this->pmssWriteFile($config, "# ignored\nscgi_port = 127.0.0.1:6050\ndht.port.set = 0\nnetwork.port_range.set = 20000-60000\n");
        $this->pmssWriteFile($base.'/scgi/5050', 'user1234');
        $this->pmssWriteFile($base.'/scgi/6050', 'legacy');
        $this->pmssWriteFile($base.'/dht/5051', 'user1234');
        $this->pmssWriteFile($base.'/dht/0', 'invalid');
        $this->pmssWriteFile($base.'/listen/5052', 'user1234');
        $this->pmssWriteFile($base.'/listen/20000', 'static-template');

        $this->assertTrue(\pmssTerminateUserReleaseRtorrentPortReservations('user1234', $config, true, $base, $store));
        $this->assertSame(array(true, true, true), array(is_file($base.'/scgi/5050'), is_file($base.'/dht/5051'), is_file($base.'/listen/5052')));

        $this->assertTrue(\pmssTerminateUserReleaseRtorrentPortReservations('user1234', $config, false, $base, $store));
        $this->assertSame(
            array(false, true, false, true, false, true, true, true),
            array(
                file_exists($base.'/scgi/5050'),
                is_file($base.'/scgi/6050'),
                file_exists($base.'/dht/5051'),
                is_file($base.'/dht/0'),
                file_exists($base.'/listen/5052'),
                is_file($base.'/listen/20000'),
                is_dir($base.'/dht'),
                is_dir($base.'/listen')
            )
        );
    }

    public function testTerminateUserRtorrentPortCleanupKeepsLegacyFallback(): void
    {
        $base = $this->pmssMakeTempDir('pmss-terminate-legacy-ports-');
        $store = new \UserConfigStore($this->pmssMakeTempDir('pmss-terminate-empty-store-').'/seedbox/config');
        $config = $this->pmssMakeTempFile('pmss-terminate-legacy-ports-config-');
        $this->pmssWriteFile($config, "scgi_port = localhost:6060\ndht_port = 6061\nport_range = 6062-6062\nnetwork.port_range.set = 20000-60000\n");
        $this->pmssWriteFile($base.'/scgi/6060', 'legacy');
        $this->pmssWriteFile($base.'/dht/6061', 'legacy');
        $this->pmssWriteFile($base.'/listen/6062', 'legacy');
        $this->pmssWriteFile($base.'/listen/20000', 'static-template');

        $this->assertTrue(\pmssTerminateUserReleaseRtorrentPortReservations('user1234', $config, false, $base, $store));
        $this->assertSame(
            array(false, false, false, true, true),
            array(
                file_exists($base.'/scgi/6060'),
                file_exists($base.'/dht/6061'),
                file_exists($base.'/listen/6062'),
                is_file($base.'/listen/20000'),
                is_dir($base.'/listen')
            )
        );
    }
}
