<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/configPermissions.php';

class ConfigPermissionsSweepTest extends TestCase
{
    public function testUsersRecordsNeverReceiveGeneralModes(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-config-permissions-', 0700);
        $usersDir = $configDir.'/users';
        $otherDir = $configDir.'/other';
        $this->pmssWriteFiles($configDir,
            ['users/fixtur01.json' => '{}', 'users/notes' => 'private', 'other/template' => 'public'], 0700);
        chmod($usersDir.'/fixtur01.json', 0600);
        chmod($usersDir.'/notes', 0600);
        $calls = [];

        \pmssConfigPermissionsSweep($configDir, static function (string $path, int $mode) use (&$calls): void {
            $calls[] = [$path, $mode];
            chmod($path, $mode);
        });

        foreach ($calls as [$path, $mode]) {
            if ($path === $usersDir) {
                $this->assertSame(0, $mode & ~0750);
            }
            if ($path === $usersDir.'/fixtur01.json') {
                $this->assertSame(0, $mode & ~0640);
            }
            $this->assertFalse($path === $usersDir.'/notes');
        }
        clearstatcache();
        $this->assertSame(0750, fileperms($usersDir) & 0777);
        $this->assertSame(0640, fileperms($usersDir.'/fixtur01.json') & 0777);
        $this->assertSame(0600, fileperms($usersDir.'/notes') & 0777);
        $this->assertSame(0775, fileperms($otherDir) & 0777);
        $this->assertSame(0664, fileperms($otherDir.'/template') & 0777);
    }

    public function testLinkedUsersTreeAndRecordAreNeverChanged(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-config-permissions-', 0700);
        $outside = $this->pmssMakeTempDir('pmss-config-outside-', 0700);
        $this->pmssWriteFile($outside.'/fixtur01.json', '{}');
        chmod($outside.'/fixtur01.json', 0600);
        symlink($outside, $configDir.'/users');
        \pmssConfigPermissionsSweep($configDir);
        clearstatcache();
        $this->assertSame(0600, fileperms($outside.'/fixtur01.json') & 0777);
    }
}
