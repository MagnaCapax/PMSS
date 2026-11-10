<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/userRepository.php';

class UserRepositoryTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'repo', 0755, sys_get_temp_dir().'/pmss-userrepo-tests']; }

    public function testPersistAndReload(): void
    {
            $repo = new \UserRepository($this->tempDir.'/seedbox/config');
            $this->assertEquals([], $repo->all());

            $payload = [
                'ramMiB'       => 512,
                'rtorrentPort' => 5000,
                'quota'        => 100,
                'quotaBurst'   => 125,
                'customNote'   => 'keep-me',
            ];
            $this->assertTrue($repo->set('fixtur01', $payload));

            $userFile = $this->tempDir.'/seedbox/config/users/fixtur01.json';
            $this->assertTrue(is_file($userFile), 'Per-user file should be written');

            $repo2 = new \UserRepository($this->tempDir.'/seedbox/config');
            $users = $repo2->all();
            $this->assertTrue(isset($users['fixtur01']));
            $this->assertEquals(512, $users['fixtur01']['ramMiB']);
            $this->assertEquals('keep-me', $users['fixtur01']['customNote']);
    }

    public function testLegacyRtorrentRamIsAccepted(): void
    {
            $repo = new \UserRepository($this->tempDir.'/seedbox/config');
            $payload = [
                'rtorrentRam'  => 256,
                'rtorrentPort' => 4100,
                'quota'        => 10,
                'quotaBurst'   => 12,
            ];
            $this->assertTrue($repo->set('fixtur02', $payload));
            $reloaded = $repo->get('fixtur02');
            $this->assertTrue(is_array($reloaded));
            $this->assertEquals(256, $reloaded['ramMiB']);
            $this->assertTrue(isset($reloaded['rtorrentRam']), 'Legacy key should be preserved');
    }

    public function testInvalidPayloadIsRejected(): void
    {
            $repo = new \UserRepository($this->tempDir.'/seedbox/config');
            $this->assertTrue($repo->set('badUser', [
                'ramMiB'     => 128,
                'quota'      => 40,
                'quotaBurst' => 50,
            ]) === false);
    }
}
