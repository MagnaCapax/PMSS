<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/deluge.php';

final class DelugeConfigWriteSafetyTest extends TestCase
{
    public function testMissingDirectoriesAreCreatedAsAccountUser(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/deluge.php');
        $this->assertStringContainsString("pmssBuildUserShellCommand(\$username, 'mkdir -p -- '.escapeshellarg(\$configDir))", $source);
        $this->assertStringContainsString("pmssBuildUserShellCommand(\$username, 'mkdir -p -- '.escapeshellarg(\$dir))", $source);
        $this->assertFalse(strpos($source, "sprintf('chown %1\$s -R %2\$s', escapeshellarg(\$username.':'.\$username), escapeshellarg(\$dir))") !== false);
    }

    public function testRootOwnershipWalkAndTemplateCopyAreAbsent(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/deluge.php');
        $this->assertStringNotContainsString('Fixing Deluge ownership', $source);
        $this->assertStringNotContainsString('Provisioning Deluge auth template', $source);
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
        $this->assertSame($uid, fileowner($home.'/.delugePort'));
    }
}
