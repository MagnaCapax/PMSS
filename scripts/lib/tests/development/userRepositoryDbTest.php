<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/UserRepository.php';

class UserRepositoryDbTest extends TestCase
{
    public function testPersistToCustomPath(): void
    {
        $path = sys_get_temp_dir().'/pmss-users-'.bin2hex(random_bytes(4)).'.json';
        $original = getenv('PMSS_USERS_DB_FILE');
        putenv('PMSS_USERS_DB_FILE='.$path);
        try {
            $repo = new \UserRepository();
            $repo->set('dummy', [
                'rtorrentRam'  => 1,
                'rtorrentPort' => 1,
                'quota'        => 1,
                'quotaBurst'   => 1,
            ]);
            $repo->persist();
            $this->assertTrue(file_exists($path));
            $repo = null;
        } finally {
            if ($original === false) {
                putenv('PMSS_USERS_DB_FILE');
            } else {
                putenv('PMSS_USERS_DB_FILE='.$original);
            }
            @unlink($path);
        }
    }
}
