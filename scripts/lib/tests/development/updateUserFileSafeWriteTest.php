<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update.php';

class UpdateUserFileSafeWriteTest extends TestCase
{
    private $homeRoot;
    private $user;
    private $skelDirName;
    private $skelDirPath;
    private $envBackup = [];

    protected function setUp(): void
    {
        $skelBase = \pmssSkeletonBase();
        if ($skelBase === '/etc/skel') {
            throw new SkipTest('PMSS_SKEL_DIR not set to a temp path');
        }

        $this->user = 'user'.bin2hex(random_bytes(2));
        $this->homeRoot = sys_get_temp_dir().'/pmss-userfile-'.bin2hex(random_bytes(4));
        @mkdir($this->homeRoot, 0755, true);

        $this->envBackup = $this->stashEnv(['PMSS_HOME_DIR']);
        putenv('PMSS_HOME_DIR='.$this->homeRoot);

        $this->skelDirName = 'pmss-userfile-'.bin2hex(random_bytes(3));
        $this->skelDirPath = $skelBase.'/'.$this->skelDirName;
        @mkdir($this->skelDirPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv($this->envBackup);
        $this->cleanup($this->homeRoot);
        $this->cleanup($this->skelDirPath);
    }

    public function testCreatesParentDirectoriesForMissingPath(): void
    {
        $home = $this->ensureUserHome();
        $relative = $this->skelRelative('nested/dir/config.txt');
        $this->writeSkelFile($relative, 'alpha');

        \updateUserFile($relative, $this->user);

        $target = $home.'/'.$relative;
        $this->assertTrue(is_file($target));
        $this->assertEquals('alpha', file_get_contents($target));
        $this->assertTrue(is_dir($home.'/'.$this->skelDirName.'/nested/dir'));
    }

    public function testIdempotentWhenContentMatches(): void
    {
        $home = $this->ensureUserHome();
        $relative = $this->skelRelative('same.txt');
        $this->writeSkelFile($relative, 'same');

        $target = $home.'/'.$relative;
        @mkdir(dirname($target), 0755, true);
        file_put_contents($target, 'same');
        chmod($target, 0644);
        $beforePerms = fileperms($target) & 0777;

        \updateUserFile($relative, $this->user);

        $afterPerms = fileperms($target) & 0777;
        $this->assertEquals($beforePerms, $afterPerms);
        $this->assertEquals('same', file_get_contents($target));
    }

    public function testUpdatesWhenContentDiffers(): void
    {
        $home = $this->ensureUserHome();
        $relative = $this->skelRelative('diff.txt');
        $this->writeSkelFile($relative, 'new');

        $target = $home.'/'.$relative;
        @mkdir(dirname($target), 0755, true);
        file_put_contents($target, 'old');

        \updateUserFile($relative, $this->user);

        $this->assertEquals('new', file_get_contents($target));
        $leftovers = glob(dirname($target).'/pmss-userfile-*');
        $this->assertTrue($leftovers === false || count($leftovers) === 0);
    }

    public function testSkipsWhenHomeMissing(): void
    {
        $relative = $this->skelRelative('missing-home.txt');
        $this->writeSkelFile($relative, 'data');

        $target = $this->homeRoot.'/'.$this->user.'/'.$relative;
        \updateUserFile($relative, $this->user);

        $this->assertTrue(!file_exists($target));
    }

    public function testSkipsWhenTargetIsDirectory(): void
    {
        $home = $this->ensureUserHome();
        $relative = $this->skelRelative('dir-target.txt');
        $this->writeSkelFile($relative, 'data');

        $target = $home.'/'.$relative;
        @mkdir($target, 0755, true);

        \updateUserFile($relative, $this->user);

        $this->assertTrue(is_dir($target));
    }

    private function ensureUserHome(): string
    {
        $home = $this->homeRoot.'/'.$this->user;
        if (!is_dir($home)) {
            @mkdir($home, 0755, true);
        }
        return $home;
    }

    private function skelRelative(string $suffix): string
    {
        return $this->skelDirName.'/'.$suffix;
    }

    private function writeSkelFile(string $relative, string $content): void
    {
        $path = \pmssSkeletonBase().'/'.$relative;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    private function stashEnv(array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            $value = getenv($name);
            $values[$name] = $value === false ? null : $value;
        }
        return $values;
    }

    private function restoreEnv(array $values): void
    {
        foreach ($values as $name => $value) {
            if ($value === null) {
                putenv($name);
            } else {
                putenv($name.'='.$value);
            }
        }
    }

    private function cleanup(string $path): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->cleanup($path.'/'.$item);
        }
        @rmdir($path);
    }
}
