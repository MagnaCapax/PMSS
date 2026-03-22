<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/update.php';
require_once dirname(__DIR__, 2).'/update/users.php';

class UserFilemanagerCompatPatchTest extends TestCase
{
    use FilesystemCleanupTrait;

    private $homeRoot;
    private $skelDir;
    private $user;
    private $envBackup = [];

    protected function setUp(): void
    {
        $this->homeRoot = sys_get_temp_dir().'/pmss-user-filemanager-home-'.bin2hex(random_bytes(4));
        $this->skelDir = sys_get_temp_dir().'/pmss-user-filemanager-skel-'.bin2hex(random_bytes(4));
        $this->user = 'user'.bin2hex(random_bytes(2));
        $this->envBackup = $this->stashEnv(['PMSS_HOME_DIR', 'PMSS_SKEL_DIR']);

        @mkdir($this->homeRoot.'/'.$this->user, 0755, true);
        @mkdir($this->skelDir.'/www', 0755, true);

        putenv('PMSS_HOME_DIR='.$this->homeRoot);
        putenv('PMSS_SKEL_DIR='.$this->skelDir);
    }

    protected function tearDown(): void
    {
        $this->restoreEnv($this->envBackup);
        $this->cleanup($this->homeRoot);
        $this->cleanup($this->skelDir);
    }

    public function testApplySkeletonFilesPatchesCopiedFilemanager(): void
    {
        $this->writeSkelFile('www/filemanager.php', <<<'PHP'
before
        ob_flush();
    if (strstr($_SERVER['HTTP_USER_AGENT'], "MSIE")) {
        $fileName = preg_replace('/\./', '%2e', $fileName, substr_count($fileName, '.') - 1);
        header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");
    } else {
        header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");
    }
        str_replace($range, "-", $range);
https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.slim.min.js
https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js
https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js
after
PHP
);

        \pmssUserApplySkeletonFiles($this->context());

        $content = (string) file_get_contents($this->targetPath());
        $this->assertTrue(strpos($content, '        @ob_flush();') !== false);
        $this->assertTrue(strpos($content, "\n        ob_flush();\n") === false);
        $this->assertTrue(strpos($content, 'strstr($_SERVER[\'HTTP_USER_AGENT\'], "MSIE")') === false);
        $this->assertTrue(strpos($content, 'header("Content-Disposition: $contentDisposition;filename=\\"$fileName\\"");') !== false);
        $this->assertTrue(strpos($content, '        $range = str_replace("-", "", $range);') !== false);
        $this->assertTrue(strpos($content, 'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.slim.min.js') !== false);
        $this->assertTrue(strpos($content, 'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js') !== false);
        $this->assertTrue(strpos($content, 'https://cdn.datatables.net/2.0.8/js/dataTables.min.js') !== false);
    }

    public function testApplySkeletonFilesLeavesPatchedFilemanagerUntouched(): void
    {
        $expected = <<<'PHP'
before
        @ob_flush();
    header("Content-Disposition: $contentDisposition;filename=\"$fileName\"");
        $range = str_replace("-", "", $range);
https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.slim.min.js
https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js
https://cdn.datatables.net/2.0.8/js/dataTables.min.js
after
PHP;
        $this->writeSkelFile('www/filemanager.php', $expected);

        \pmssUserApplySkeletonFiles($this->context());

        $this->assertEquals($expected, (string) file_get_contents($this->targetPath()));
    }

    public function testApplySkeletonFilesSkipsMissingFilemanagerSource(): void
    {
        \pmssUserApplySkeletonFiles($this->context());
        $this->assertTrue(!file_exists($this->targetPath()));
    }

    public function testApplySkeletonFilesSkipsSymlinkedFilemanagerTarget(): void
    {
        $this->writeSkelFile('www/filemanager.php', "before\n        ob_flush();\nafter\n");
        @mkdir(dirname($this->targetPath()), 0755, true);

        $target = $this->tempPath('patch-symlink-target');
        $link = $this->targetPath();
        file_put_contents($target, "before\n        ob_flush();\nafter\n");
        @symlink($target, $link);

        \pmssUserApplySkeletonFiles($this->context());

        $this->assertEquals("before\n        ob_flush();\nafter\n", (string) file_get_contents($target));

        @unlink($target);
    }

    private function context(): array
    {
        return [
            'user' => $this->user,
            'home' => $this->homeRoot.'/'.$this->user,
        ];
    }

    private function tempPath(string $suffix): string
    {
        return sys_get_temp_dir().'/pmss-user-filemanager-'.$suffix.'-'.bin2hex(random_bytes(4)).'.php';
    }

    private function targetPath(): string
    {
        return $this->homeRoot.'/'.$this->user.'/www/filemanager.php';
    }

    private function writeSkelFile(string $relative, string $content): void
    {
        $path = $this->skelDir.'/'.$relative;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $content);
    }

    private function stashEnv(array $names): array
    {
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = getenv($name);
        }
        return $previous;
    }

    private function restoreEnv(array $previous): void
    {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name.'='.$value);
            }
        }
    }

}
