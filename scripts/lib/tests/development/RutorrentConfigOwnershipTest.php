<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/scripts/lib/rutorrent/config.php';

class RutorrentConfigOwnershipTest extends TestCase
{
    public function testUpdateRutorrentConfigConvergesFreshFileOwnershipAndMode(): void
    {
        [$user, $uid, $gid] = $this->currentUserWithMatchingGroup();
        $configRoot = $this->pmssMakeTempDir('pmss-rutorrent-config-');
        $homeRoot = $this->pmssMakeTempDir('pmss-rutorrent-home-');
        $home = $this->pmssUserHomePath($homeRoot, $user);
        $confDir = $home.'/www/rutorrent/conf';

        $this->pmssEnsureDir($confDir);
        $this->pmssWriteFile($configRoot.'/template.rutorrent.config', $this->configTemplate());
        $this->pmssWriteFile($configRoot.'/template.rutorrent.access', "allow = test\n");

        $this->pmssWithEnv(['PMSS_CONFIG_DIR' => $configRoot, 'PMSS_HOME_DIR' => $homeRoot], function () use ($user): void {
            \updateRutorrentConfig($user, 1);
        });

        foreach ([$confDir.'/config.php', $confDir.'/access.ini'] as $path) {
            $this->assertTrue(is_file($path), 'Expected ruTorrent config file to exist: '.$path);
            $this->assertSame(0750, fileperms($path) & 0777, 'Expected restricted mode on '.$path);
            $this->assertSame($uid, fileowner($path), 'Expected user owner on '.$path);
            $this->assertSame($gid, filegroup($path), 'Expected user group on '.$path);
        }

        $config = (string) file_get_contents($confDir.'/config.php');
        $this->assertStringContainsString('$scgi_host = "unix://'.$home.'/.rtorrent.socket";', $config);
        $this->assertStringContainsString("\$tempDirectory = '{$home}/.tmp/';", $config);
        $this->assertEquals("allow = test\n", (string) file_get_contents($confDir.'/access.ini'));
    }

    public function testUpdateRutorrentConfigKeepsExplicitOwnershipConvergenceCalls(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/rutorrent/config.php');

        $this->assertStringContainsString('pmssRutorrentConfigConvergeFilePermissions($path, $username);', $source);
        $this->assertStringContainsString('@chown($path, $username)', $source);
        $this->assertStringContainsString('@chgrp($path, $username)', $source);
        $this->assertStringContainsString('@chmod($path, 0750)', $source);
    }

    private function configTemplate(): string
    {
        return <<<'PHP'
<?php
$scgi_host = "";
$tempDirectory = null;
$topDirectory = '/';
$log_file = '/tmp/errors.log';
PHP;
    }

    /**
     * PMSS user homes assume same-named user and group accounts.
     *
     * @return array{0:string,1:int,2:int}
     */
    private function currentUserWithMatchingGroup(): array
    {
        if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid') || !function_exists('posix_getgrnam')) {
            throw new SkipTest('POSIX account helpers unavailable');
        }

        $pw = posix_getpwuid(posix_geteuid());
        if (!is_array($pw) || !isset($pw['name'], $pw['uid'])) {
            throw new SkipTest('Current user account unavailable');
        }

        $group = posix_getgrnam((string) $pw['name']);
        if (!is_array($group) || !isset($group['gid'])) {
            throw new SkipTest('Current user does not have a same-named group');
        }

        return [(string) $pw['name'], (int) $pw['uid'], (int) $group['gid']];
    }
}
