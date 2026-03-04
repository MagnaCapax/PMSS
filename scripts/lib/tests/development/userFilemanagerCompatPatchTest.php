<?php
namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/user/skeleton.php';

class UserFilemanagerCompatPatchTest extends TestCase
{
    public function testPatchAddsSuppressionToObFlushCall(): void
    {
        $path = $this->tempPath('patch-adds');
        file_put_contents($path, "before\n        ob_flush();\nafter\n");

        $patched = \pmssUserFilemanagerObFlushPatchApply($path);
        $content = (string) file_get_contents($path);

        $this->assertTrue($patched);
        $this->assertTrue(strpos($content, '        @ob_flush();') !== false);
        $this->assertTrue(strpos($content, "\n        ob_flush();\n") === false);

        @unlink($path);
    }

    public function testPatchReturnsTrueWhenAlreadyPatched(): void
    {
        $path = $this->tempPath('patch-already');
        file_put_contents($path, "before\n        @ob_flush();\nafter\n");

        $patched = \pmssUserFilemanagerObFlushPatchApply($path);
        $content = (string) file_get_contents($path);

        $this->assertTrue($patched);
        $this->assertTrue(strpos($content, '        @ob_flush();') !== false);

        @unlink($path);
    }

    public function testPatchReturnsFalseWhenTargetMissing(): void
    {
        $path = $this->tempPath('patch-missing');

        $this->assertTrue(!\pmssUserFilemanagerObFlushPatchApply($path));
    }

    public function testPatchReturnsFalseForSymlinkPath(): void
    {
        $target = $this->tempPath('patch-symlink-target');
        $link = $this->tempPath('patch-symlink-link');
        file_put_contents($target, "before\n        ob_flush();\nafter\n");
        @symlink($target, $link);

        $this->assertTrue(!\pmssUserFilemanagerObFlushPatchApply($link));

        @unlink($link);
        @unlink($target);
    }

    public function testPatchReturnsFalseWhenPatternIsMissing(): void
    {
        $path = $this->tempPath('patch-no-pattern');
        file_put_contents($path, "before\n        flush();\nafter\n");

        $this->assertTrue(!\pmssUserFilemanagerObFlushPatchApply($path));

        @unlink($path);
    }

    private function tempPath(string $suffix): string
    {
        return sys_get_temp_dir().'/pmss-user-filemanager-'.$suffix.'-'.bin2hex(random_bytes(4)).'.php';
    }
}

}
