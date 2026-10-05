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

    public function testOwnershipWalkFiltersLinkedFiles(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/deluge.php');
        $this->assertStringContainsString('find %s -not -type l \( ! -type f -o -links 1 \) -exec chown -h %s {} +', $source);
        $this->assertFalse(strpos($source, "sprintf('chown %1\$s -R %2\$s'") !== false);
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
}
