<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/userRepository.php';

class UserRepositoryTest extends TestCase
{
    /** @var string */
    private $tempDir;

    private function configDirPath(): string
    {
        return $this->tempDir.'/seedbox/config';
    }

    private function setUpTempDir(): void
    {
        $base = sys_get_temp_dir().'/pmss-userrepo-tests';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        $this->tempDir = $base.'/repo-'.bin2hex(random_bytes(4));
        @mkdir($this->tempDir, 0755, true);
    }

    private function tearDownTempDir(): void
    {
        if (empty($this->tempDir) || !is_dir($this->tempDir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            if ($path->isDir()) {
                @rmdir($path->getPathname());
            } else {
                @unlink($path->getPathname());
            }
        }
        @rmdir($this->tempDir);
        $this->tempDir = '';
    }

    public function testPersistAndReload(): void
    {
        $this->setUpTempDir();
        try {
            $repo = new \UserRepository($this->configDirPath());
            $this->assertEquals([], $repo->all());

            $payload = [
                'ramMiB'       => 512,
                'rtorrentPort' => 5000,
                'quota'        => 100,
                'quotaBurst'   => 125,
                'customNote'   => 'keep-me',
            ];
            $this->assertTrue($repo->set('alice', $payload));

            $userFile = $this->configDirPath().'/users/alice.json';
            $this->assertTrue(is_file($userFile), 'Per-user file should be written');

            $repo2 = new \UserRepository($this->configDirPath());
            $users = $repo2->all();
            $this->assertTrue(isset($users['alice']));
            $this->assertEquals(512, $users['alice']['ramMiB']);
            $this->assertEquals('keep-me', $users['alice']['customNote']);
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testLegacyRtorrentRamIsAccepted(): void
    {
        $this->setUpTempDir();
        try {
            $repo = new \UserRepository($this->configDirPath());
            $payload = [
                'rtorrentRam'  => 256,
                'rtorrentPort' => 4100,
                'quota'        => 10,
                'quotaBurst'   => 12,
            ];
            $this->assertTrue($repo->set('bob', $payload));
            $reloaded = $repo->get('bob');
            $this->assertTrue(is_array($reloaded));
            $this->assertEquals(256, $reloaded['ramMiB']);
            $this->assertTrue(isset($reloaded['rtorrentRam']), 'Legacy key should be preserved');
        } finally {
            $this->tearDownTempDir();
        }
    }

    public function testInvalidPayloadIsRejected(): void
    {
        $this->setUpTempDir();
        try {
            $repo = new \UserRepository($this->configDirPath());
            $this->assertTrue($repo->set('badUser', [
                'ramMiB'     => 128,
                'quota'      => 40,
                'quotaBurst' => 50,
            ]) === false);
        } finally {
            $this->tearDownTempDir();
        }
    }
}
