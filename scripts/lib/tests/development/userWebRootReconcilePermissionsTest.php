<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users.php';

/** Ownership convergence for scripts/lib/update/users/webRootReconcile.php. */
class UserWebRootReconcilePermissionsTest extends TestCase
{
    public function testFullAndPartialRecoveryPreserveClassesForRealNonRootAccount(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new SkipTest('Root watchdog and real non-root account required');
        }
        foreach (['full', 'empty', 'partial'] as $mode) {
            $result = $this->reconcileFixture($mode, 0, true);
            $this->assertTrue($result['ok'], implode('|', $result['messages']));
            $this->assertTrue($result['account']['uid'] > 0);
            foreach (['index.php', 'rutorrent/index.html'] as $path) {
                $this->assertSame([$result['account']['uid'], $result['account']['gid'], 0640], $result['metadata'][$path]);
            }
            foreach (['', 'rutorrent', 'rutorrent/conf'] as $path) {
                $this->assertSame([$result['account']['uid'], $result['account']['gid']], array_slice($result['metadata'][$path], 0, 2));
            }
            $this->assertSame([0, 0, 0754], $result['metadata']['rutorrent/conf/config.php']);
            $this->assertSame(1, count($result['steps']));
            $this->pmssAssertArraySubsetSame(['command' => $result['expectedCommand'],
                'config' => $mode === 'partial' ? 'skeleton-config' : 'generated-config'], $result['steps'][0]);
            $this->pmssAssertArraySubsetSame(['customer' => 'customer-data',
                'panelHash' => hash('sha256', "panel\0\xff\n"), 'emptySize' => 0], $result);
        }
    }

    public function testHealthyAndLockedRootsDoNotRunPermissionRepair(): void
    {
        foreach (['healthy', 'locked'] as $mode) {
            $result = $this->reconcileFixture($mode);
            $this->pmssAssertArraySubsetSame(['ok' => $mode === 'healthy', 'steps' => []], $result);
        }
    }

    public function testNonzeroAndTimeoutRepairsFailWithVisibleReason(): void
    {
        foreach (['full', 'partial'] as $mode) foreach ([1, 23, 124] as $rc) {
            $result = $this->reconcileFixture($mode, $rc);
            $this->assertFalse($result['ok']);
            $this->assertTrue($result['lockReleased']);
            $this->assertSame(1, count($result['steps']));
            $this->assertStringContainsAllStrings(['permission repair failed rc='.$rc,
                'reason=permissions-failed'], implode('|', $result['messages']));
        }
    }

    public function testSecondReconcileDoesNotRepeatPermissionRepair(): void
    {
        $result = $this->reconcileFixture('repeat');
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['second']);
        $this->assertSame(1, count($result['steps']));
    }

    public function testUnsafeSkeletonDoesNotRunPermissionRepair(): void
    {
        $result = $this->reconcileFixture('unsafe');
        $this->assertFalse($result['ok']);
        $this->pmssAssertArraySubsetSame(['steps' => [], 'customer' => 'customer-data'], $result);
        $this->assertTrue($result['lockReleased']);
    }

    /** Redirect only the CLI boundary; apply its real web-root policy in a disposable home. */
    private function reconcileFixture(string $mode, int $rc = 0, bool $nonRoot = false): array
    {
        $account = posix_getpwnam($nonRoot ? 'nobody' : posix_getpwuid(posix_geteuid())['name']);
        if (!is_array($account)) throw new SkipTest('Fixture account unavailable');
        $root = $this->pmssMakeTempDir('pmss-web-permissions-');
        $homeRoot = $this->pmssEnsureDir($root.'/homes');
        $home = $this->pmssEnsureDir($homeRoot.'/'.$account['name']);
        $skel = $this->pmssEnsureDir($root.'/skel');
        $config = $this->pmssEnsureDir($root.'/config');
        $locks = $this->pmssEnsureDir($root.'/locks');
        $this->pmssWriteFiles($skel.'/www', ['index.php' => "panel\0\xff\n", 'empty.php' => '',
            'rutorrent/index.html' => 'rutorrent', 'rutorrent/conf/config.php' => 'skeleton-config']);
        $this->pmssWriteFiles($config, ['template.rutorrent.config' => 'generated-config', 'template.rutorrent.access' => 'access']);
        if ($mode === 'healthy') $this->assertTrue(\pmssTestingCopyTree($skel.'/www', $home.'/www', 0755));
        if ($mode === 'empty') $this->pmssEnsureDir($home.'/www');
        if (in_array($mode, ['partial', 'repeat'], true)) $this->pmssWriteFile($home.'/www/customer.txt', 'customer-data');
        $this->pmssWriteFile($home.'/data/customer.txt', 'customer-data');
        if ($mode === 'unsafe') $this->pmssCreateSymlinkOrSkip($home.'/data/customer.txt', $skel.'/www/unsafe.php');
        // Match production: the skeleton stays root-only while home entries belong to the account.
        foreach ([$home, $skel] as $tree) {
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tree, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach (array_merge([$tree], array_keys(iterator_to_array($entries))) as $path) {
                if (is_link($path)) continue;
                if ($tree === $home && posix_geteuid() === 0) { chown($path, $account['uid']); chgrp($path, $account['gid']); }
                if (is_file($path)) chmod($path, 0640);
            }
        }
        chmod($skel, 0770);
        $script = 'namespace WebRootPermissionsFixture; require '.var_export(dirname(__DIR__, 2).'/update/users.php', true).';'
            .'require '.var_export(dirname(__DIR__, 2).'/user/permissionsApply.php', true).';'
            .'require '.var_export(dirname(__DIR__, 2).'/user/permissionsPolicy.php', true).';'
            .'require_once '.var_export(dirname(__DIR__, 2).'/shell.php', true).';'
            .'$home='.var_export($home, true).';$account='.var_export($account, true).';'
            .'$mode='.var_export($mode, true).';$rc='.$rc.';'
            .\pmssTestingFunctionExport('pmssUserReconcileWebRoot', 'WebRootPermissionsFixture');
        $script .= <<<'PHP'
function runUserStep($user, $description, $command) {
    global $home, $account, $rc, $steps;
    $steps[] = ['command' => $command, 'config' => file_get_contents($home.'/www/rutorrent/conf/config.php')];
    if ($rc !== 0 || posix_geteuid() !== 0) return $rc;
    $paths = array_fill_keys(['normal', 'local', 'ingress', 'ingressLocal'], $home.'/absent');
    [$modes, $owners] = \pmssUserPermissionsPolicy($user, $paths);
    // Keep the CLI's policy and inode-safe operations, bounded to fixture www entries.
    foreach ([$owners, $modes] as $phase => $items) foreach ($items as $item) {
        $prefix = '/home/'.$user.'/www/';
        if (strpos($item[0], $prefix) !== 0) continue;
        $path = $home.'/www/'.substr($item[0], strlen($prefix));
        if (!file_exists($path) || is_link($path)) continue;
        if ($phase === 0) \pmssUserPermissionsOwnerApply($home, $path, $item[1]);
        else \pmssUserPermissionsModeApply($user, $home, $account['uid'], $path, $item[1]);
    }
    return $GLOBALS['PMSS_USER_PERMISSIONS_FAILED_COUNT'] ?? 0;
}
$steps = $messages = [];
ob_start();
$ctx = ['user' => $account['name'], 'home' => $home];
$logger = static function ($message) use (&$messages) { $messages[] = $message; };
$lock = $mode === 'locked' ? \pmssLockFileAcquire(getenv('PMSS_USER_WEB_ROOT_LOCK_DIR').'/pmss-user-web-root-'.$account['name'].'.lock', true) : false;
$ok = pmssUserReconcileWebRoot($ctx, $logger);
if (is_resource($lock)) \pmssLockHandleRelease($lock);
$second = $mode === 'repeat' ? pmssUserReconcileWebRoot($ctx, $logger) : null;
clearstatcache();
$metadata = [];
foreach (['', 'rutorrent', 'rutorrent/conf', 'index.php', 'rutorrent/index.html', 'rutorrent/conf/config.php'] as $relative) {
    $stat = @lstat($home.'/www/'.$relative);
    $metadata[$relative] = is_array($stat) ? [$stat['uid'], $stat['gid'], $stat['mode'] & 07777] : null;
}
$released = \pmssLockFileAcquire(getenv('PMSS_USER_WEB_ROOT_LOCK_DIR').'/pmss-user-web-root-'.$account['name'].'.lock', true);
$lockReleased = is_resource($released);
if ($lockReleased) \pmssLockHandleRelease($released);
ob_end_clean();
echo json_encode(['ok' => $ok, 'second' => $second, 'steps' => $steps, 'messages' => $messages,
    'metadata' => $metadata, 'account' => $account, 'lockReleased' => $lockReleased, 'customer' => file_get_contents($home.'/data/customer.txt'),
    'panelHash' => @hash_file('sha256', $home.'/www/index.php'), 'emptySize' => @filesize($home.'/www/empty.php'),
    'expectedCommand' => \pmssBuildCommand('/scripts/util/userPermissions.php', [$account['name']])]);
PHP;
        return $this->pmssRunInlinePhpJson($script, ['PMSS_HOME_DIR' => $homeRoot, 'PMSS_SKEL_DIR' => $skel,
            'PMSS_CONFIG_DIR' => $config, 'PMSS_USER_WEB_ROOT_LOCK_DIR' => $locks, 'PMSS_LOG_DIR' => $root.'/logs']);
    }
}
