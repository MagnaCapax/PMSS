<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/UserRepository.php';

class UserRepositoryTest extends TestCase
{
    private string $tempFile;

    protected function setUpTempFile(): void
    {
        $dir = sys_get_temp_dir().'/pmss-userrepo-tests';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $this->tempFile = tempnam($dir, 'users-');
        if ($this->tempFile === false) {
            $this->tempFile = $dir.'/users-'.bin2hex(random_bytes(4));
        }
        // ensure file removed so repository starts clean
        @unlink($this->tempFile);
        putenv('PMSS_USERS_DB_FILE='.$this->tempFile);
    }

    protected function tearDownTempFile(): void
    {
        if (!empty($this->tempFile) && file_exists($this->tempFile)) {
            @unlink($this->tempFile);
        }
        putenv('PMSS_USERS_DB_FILE');
    }

    public function testPersistAndReload(): void
    {
        $this->setUpTempFile();
        try {
            $repo = new \UserRepository();
            $this->assertEquals([], $repo->all(), 'Fresh repository should be empty');

            $payload = [
                'rtorrentRam'  => 512,
                'rtorrentPort' => 5000,
                'quota'        => 100,
                'quotaBurst'   => 125,
            ];
            $this->assertTrue($repo->set('alice', $payload));
            $this->assertTrue($repo->persist());
            $this->assertTrue(file_exists($this->tempFile));

            // Recreate repository to ensure data is read back
            $repo2 = new \UserRepository();
            $users = $repo2->all();
            $this->assertTrue(isset($users['alice']), 'Reloaded repository should contain alice');
            $this->assertEquals($payload['quota'], $users['alice']['quota']);
            $repo = null;
            $repo2 = null;
        } finally {
            $this->tearDownTempFile();
        }
    }

    public function testInvalidPayloadIsRejected(): void
    {
        $this->setUpTempFile();
        try {
            $repo = new \UserRepository();
            $this->assertTrue($repo->set('validUser', [
                'rtorrentRam'  => 256,
                'rtorrentPort' => 4500,
                'quota'        => 50,
                'quotaBurst'   => 60,
            ]));
            $this->assertTrue($repo->set('another', [
                'rtorrentRam'  => 128,
                'rtorrentPort' => 4400,
                'quota'        => 40,
                'quotaBurst'   => 50,
            ]));
            $this->assertEquals(2, count($repo->all()));

            $this->assertTrue($repo->set('badUser', [
                'rtorrentRam'  => 128,
                'quota'        => 40,
                'quotaBurst'   => 50,
            ]) === false, 'Missing rtorrentPort should be rejected');
            $repo = null;
        } finally {
            $this->tearDownTempFile();
        }
    }
}
