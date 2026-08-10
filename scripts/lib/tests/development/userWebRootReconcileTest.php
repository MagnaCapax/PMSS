<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/users.php';

/** Hermetic coverage for the shared web-root recovery contract. */
class UserWebRootReconcileTest extends TestCase
{
    private $homeRoot;
    private $home;
    private $skeleton;
    private $config;
    private $user = 'reconcileuser';
    protected function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-web-reconcile-');
        $this->home = $this->pmssUserHomePath($this->homeRoot, $this->user);
        $this->pmssEnsureDir($this->home.'/data');
        $this->skeleton = $this->pmssMakeTempDir('pmss-web-reconcile-skel-').'/www';
        $this->config = $this->pmssMakeTempDir('pmss-web-reconcile-config-');
        $this->pmssSeedSkeleton();
        $this->pmssSeedRutorrentTemplates();
        $this->pmssTrackEnvOverrides([
            'PMSS_SKEL_DIR' => dirname($this->skeleton),
            'PMSS_CONFIG_DIR' => $this->config,
            'PMSS_USER_WEB_ROOT_LOCK_DIR' => $this->pmssMakeTempDir('pmss-web-reconcile-lock-'),
        ]);
    }
    public function testFullRestoreInstallsSkeletonLinksAndGeneratedConfig(): void
    {
        $this->pmssEnsureDir($this->home.'/www');
        $this->pmssWriteFile($this->home.'/.local/share/pmss/rutorrent/share/state.ini', 'durable');
        $messages = [];
        $this->assertTrue(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)), implode('|', $messages));
        $this->assertTrue(is_dir($this->home.'/www'));
        $this->assertEquals('panel', file_get_contents($this->home.'/www/index.php'));
        $this->assertEquals('rutorrent', file_get_contents($this->home.'/www/rutorrent/index.html'));
        $this->assertTrue(is_link($this->home.'/www/data'), 'data link missing');
        $this->assertSame('../data', readlink($this->home.'/www/data'));
        $this->assertSame('../../.local/share/pmss/rutorrent/share', readlink($this->home.'/www/rutorrent/share'));
        $this->assertStringContainsString('unix://'.$this->home.'/.rtorrent.socket', file_get_contents($this->home.'/www/rutorrent/conf/config.php'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Restored complete web root'), implode('|', $messages));
    }
    public function testHealthyRootIsIdempotent(): void
    {
        $messages = [];
        $this->assertTrue(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)), implode('|', $messages));
        $before = sha1((string) file_get_contents($this->home.'/www/index.php')).':'.readlink($this->home.'/www/data');
        $messages = [];
        $this->assertTrue(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)));
        $after = sha1((string) file_get_contents($this->home.'/www/index.php')).':'.readlink($this->home.'/www/data');
        $this->assertSame($before, $after);
        $this->assertFalse($this->pmssMessagesContain($messages, 'Restored complete web root'));
    }
    public function testPartialMergeAddsMissingEntriesWithoutOverwritingCustomerFiles(): void
    {
        $this->pmssEnsureDir($this->home.'/www');
        $this->pmssWriteFile($this->home.'/www/index.php', 'customer-panel');
        $this->pmssWriteFile($this->home.'/www/customer.txt', 'customer-data');
        $outside = $this->pmssMakeTempDir('pmss-web-reconcile-outside-');
        $this->pmssCreateSymlinkOrSkip($outside.'/unsafe.php', $this->skeleton.'/unsafe.php');
        $messages = [];
        $this->assertTrue(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)));
        $this->assertEquals('customer-panel', file_get_contents($this->home.'/www/index.php'));
        $this->assertEquals('customer-data', file_get_contents($this->home.'/www/customer.txt'));
        $this->assertEquals('rutorrent', file_get_contents($this->home.'/www/rutorrent/index.html'));
        $this->assertFalse(is_link($this->home.'/www/unsafe.php'));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Refusing unsafe skeleton symlink: unsafe.php'));
        $this->assertFalse($this->pmssMessagesContain($messages, 'Restored complete web root'));
    }
    public function testPerUserLockSkipsWithoutChangingRoot(): void
    {
        $lockDir = getenv('PMSS_USER_WEB_ROOT_LOCK_DIR');
        $this->assertTrue(is_string($lockDir) && $lockDir !== '');
        $lockPath = $lockDir.'/pmss-user-web-root-'.$this->user.'.lock';
        $handle = pmssLockFileAcquire($lockPath, true);
        $this->assertTrue(is_resource($handle));
        try {
            $messages = [];
            $this->assertFalse(pmssUserReconcileWebRoot($this->context(), $this->logger($messages)));
            $this->assertFalse(is_dir($this->home.'/www'));
            $this->assertTrue($this->pmssMessagesContain($messages, 'per-user lock is busy'));
        } finally {
            pmssLockHandleRelease($handle);
        }
    }
    private function context(): array { return ['user' => $this->user, 'home' => $this->home]; }
    private function logger(array &$messages): callable { return static function (string $message) use (&$messages): void { $messages[] = $message; }; }

    private function pmssSeedSkeleton(): void
    {
        $this->pmssWriteFile($this->skeleton.'/index.php', 'panel');
        $this->pmssWriteFile($this->skeleton.'/rutorrent/index.html', 'rutorrent');
        $this->pmssWriteFile($this->skeleton.'/rutorrent/conf/config.php', 'skeleton-config');
        $this->pmssCreateSymlinkOrSkip('../data', $this->skeleton.'/data');
    }
    private function pmssSeedRutorrentTemplates(): void
    {
        $this->pmssWriteFile($this->config.'/template.rutorrent.config', '$scgi_host = "";');
        $this->pmssWriteFile($this->config.'/template.rutorrent.access', 'access');
    }
}
