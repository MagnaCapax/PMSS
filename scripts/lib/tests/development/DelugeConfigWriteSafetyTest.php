<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/deluge.php';

final class DelugeConfigWriteSafetyTest extends TestCase
{
    public function testMissingDirectoriesAreCreatedAsAccountUser(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/deluge.php');
        $this->assertStringContainsAllStrings(["'Deluge config'     => \$configDir",
            "pmssBuildUserShellCommand(\$username, 'mkdir -p -- '.escapeshellarg(\$dir))"], $source);
        $this->assertFalse(strpos($source, "sprintf('chown %1\$s -R %2\$s', escapeshellarg(\$username.':'.\$username), escapeshellarg(\$dir))") !== false);
    }

    public function testRootOwnershipWalkAndTemplateCopyAreAbsent(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/user/deluge.php', [],
            ['Fixing Deluge ownership', 'Provisioning Deluge auth template']);
    }

    public function testConfigWriteCreatesRegularFile(): void
    {
        $path = $this->pmssMakeTempDir('pmss-deluge-config-write-', 0700).'/core.conf';

        $this->assertTrue(\pmssDelugeConfigFileWrite($path, "{\"ok\": true}\n", 'core'));
        $this->assertSame("{\"ok\": true}\n", (string) file_get_contents($path));
        $this->assertFalse(is_link($path), 'Deluge config writer must create a regular file');
    }

    public function testConfigWriteRefusesSymlinkTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-deluge-config-link-', 0700);
        [$target, $link] = $this->pmssCreateSymlinkedFileOrSkip(
            $root.'/target.conf',
            $root.'/core.conf',
            "old\n",
            0700
        );

        $this->assertFalse(\pmssDelugeConfigFileWrite($link, "new\n", 'core'));
        $this->assertSame("old\n", (string) file_get_contents($target));
    }

    private function accountFixture(): array
    {
        $this->pmssResetRuntimeProfile();
        $account = posix_getpwuid(posix_geteuid());
        $username = $account['name'];
        $root = $this->pmssMakeTrackedHomeRoot('pmss-deluge-configure-');
        $home = $root.'/'.$username;
        mkdir($home);
        $this->pmssTrackEnvOverrides([
            'PMSS_SEEDBOX_CONFIG_DIR' => $this->pmssRepoPath('etc/seedbox/config'),
            'PMSS_DELUGE_AUTH_TEMPLATE_PATH' => $this->pmssRepoPath('etc/seedbox/config/template.deluge.auth'),
            'PMSS_PORT_MANAGER_DIR' => $root.'/ports',
            'PMSS_DRY_RUN' => '1',
        ]);
        return [$username, $home];
    }

    private function assertSymlinkedRootUntouched(bool $nested): void
    {
        list($username, $home) = $this->accountFixture();
        $target = $this->pmssMakeTempDir('pmss-deluge-target-');
        file_put_contents($target.'/sentinel', 'unchanged');
        $owner = fileowner($target);
        $fileOwner = fileowner($target.'/sentinel');
        if ($nested) {
            mkdir($home.'/.config');
            symlink($target, $home.'/.config/deluge');
        } else {
            symlink($target, $home.'/.config');
        }

        \userConfigureDeluge(['name' => $username, 'memory' => 4], ['config' => ['scgiPort' => 6200]]);

        $this->assertSame($owner, fileowner($target));
        $this->assertSame($fileOwner, fileowner($target.'/sentinel'));
        $this->assertSame('unchanged', file_get_contents($target.'/sentinel'));
        $this->assertFalse(file_exists($target.'/auth'));
        $this->assertFalse(is_dir($home.'/dataUnfinished'));
        $this->assertSame([], array_values(array_filter($this->pmssProfileCommands())));
    }

    public function testSymlinkedConfigRootIsUntouched(): void
    {
        $this->assertSymlinkedRootUntouched(false);
    }

    public function testSymlinkedDelugeDirectoryIsUntouched(): void
    {
        $this->assertSymlinkedRootUntouched(true);
    }

    public function testNormalConfigAndAuthBelongToAccount(): void
    {
        list($username, $home) = $this->accountFixture();
        $configDir = $home.'/.config/deluge';
        mkdir($configDir, 0755, true);
        file_put_contents($home.'/.delugeWebPort', '6201');

        \userConfigureDeluge(['name' => $username, 'memory' => 4], ['config' => ['scgiPort' => 6200]]);

        $uid = posix_getpwnam($username)['uid'];
        foreach (['core.conf', 'hostlist.conf', 'web.conf', 'auth'] as $name) {
            $path = $configDir.'/'.$name;
            $this->assertTrue(is_file($path), 'Missing '.$name);
            $this->assertSame($uid, fileowner($path), 'Wrong owner for '.$name);
        }
        $this->assertSame(0600, fileperms($configDir.'/auth') & 0777);
        $this->assertSame(0600, fileperms($configDir.'/hostlist.conf') & 0777);
        $this->assertSame(0600, fileperms($configDir.'/web.conf') & 0777);
        $this->assertSame($uid, fileowner($home.'/.delugePort'));
        $password = \pmssDelugeAuthLocalclientPasswordRead($configDir.'/auth');
        $hostlist = \pmssDelugeConfigDecode((string) file_get_contents($configDir.'/hostlist.conf'));
        $web = \pmssDelugeReadWebConf($configDir.'/web.conf');
        $this->assertTrue($password !== '' && $password !== '##LOCALCLIENT_PASSWORD##');
        $this->assertSame($password, $hostlist['config']['hosts'][0][4]);
        $this->assertSame(sha1($web['config']['pwd_salt'].$password), $web['config']['pwd_sha1']);
    }

    public function testExistingAuthUsersSurviveConfiguration(): void
    {
        foreach ([
            'missing' => "extra:thin-client-secret:5\n",
            'crlf' => "localclient:kept-secret:10\r\nextra:thin-client-secret:5\r\n",
            'lf' => "localclient:kept-secret:10\nextra:thin-client-secret:5\n",
            'empty' => "localclient::10\nextra:thin-client-secret:5\n",
            'no-final-newline' => 'extra:thin-client-secret:5',
        ] as $case => $content) {
            [$username, $home] = $this->accountFixture();
            $configDir = $this->pmssEnsureDir($home.'/.config/deluge');
            $auth = $this->pmssWriteFile($configDir.'/auth', $content);
            file_put_contents($home.'/.delugeWebPort', '6201');

            \userConfigureDeluge(['name' => $username, 'memory' => 4], ['config' => ['scgiPort' => 6200]]);

            $after = file_get_contents($auth);
            $this->assertStringContainsString('extra:thin-client-secret:5', $after, $case);
            $this->assertSame(1, substr_count($after, 'localclient:'), $case);
            $password = \pmssDelugeAuthLocalclientPasswordRead($auth);
            $this->assertTrue($password !== '', $case);
            if ($case === 'crlf' || $case === 'lf') $this->assertSame('kept-secret', $password, $case);
        }
    }

    public function testRefusedExistingAuthIsNeverReplacedFromTemplate(): void
    {
        foreach (['oversized', 'hardlinked'] as $case) {
            [$username, $home] = $this->accountFixture();
            $configDir = $this->pmssEnsureDir($home.'/.config/deluge');
            $content = "extra:thin-client-secret:5\n".($case === 'oversized' ? str_repeat('# padding\n', 9000) : '');
            $auth = $this->pmssWriteFile($configDir.'/auth', $content);
            if ($case === 'hardlinked') $this->assertTrue(link($auth, $configDir.'/auth-copy'));
            file_put_contents($home.'/.delugeWebPort', '6201');

            \userConfigureDeluge(['name' => $username, 'memory' => 4], ['config' => ['scgiPort' => 6200]]);

            $this->assertSame($content, file_get_contents($auth), $case);
            $this->assertFalse(file_exists($configDir.'/core.conf'), $case);
        }
    }
}
