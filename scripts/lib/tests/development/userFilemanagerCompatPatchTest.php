<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update.php';
require_once dirname(__DIR__, 2).'/update/users.php';

class UserFilemanagerCompatPatchTest extends TestCase
{
    private $homeRoot;
    private $skelDir;
    private $user;

    protected function setUp(): void
    {
        $this->homeRoot = $this->pmssMakeTempDir('pmss-user-filemanager-home-');
        $this->skelDir = $this->pmssMakeTempDir('pmss-user-filemanager-skel-');
        $this->user = 'user'.bin2hex(random_bytes(2));

        @mkdir($this->homeRoot.'/'.$this->user, 0755, true);
        @mkdir($this->skelDir.'/www', 0755, true);

        $this->pmssTrackEnvOverrides([
            'PMSS_HOME_DIR' => $this->homeRoot,
            'PMSS_SKEL_DIR' => $this->skelDir,
        ]);
    }

    public function testApplySkeletonFilesPatchesCopiedFilemanager(): void
    {
        $this->pmssWriteRelativeFile($this->skelDir, 'www/filemanager.php', <<<'PHP'
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

        \pmssUserApplySkeletonFiles($this->pmssUserHomeContext($this->homeRoot, $this->user));

        $content = (string) file_get_contents($this->pmssUserHomePath($this->homeRoot, $this->user, 'www/filemanager.php'));
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
        $this->pmssWriteRelativeFile($this->skelDir, 'www/filemanager.php', $expected);

        \pmssUserApplySkeletonFiles($this->pmssUserHomeContext($this->homeRoot, $this->user));

        $this->assertEquals($expected, (string) file_get_contents($this->pmssUserHomePath($this->homeRoot, $this->user, 'www/filemanager.php')));
    }

    public function testApplySkeletonFilesSkipsMissingFilemanagerSource(): void
    {
        \pmssUserApplySkeletonFiles($this->pmssUserHomeContext($this->homeRoot, $this->user));
        $this->assertTrue(!file_exists($this->pmssUserHomePath($this->homeRoot, $this->user, 'www/filemanager.php')));
    }

    public function testApplySkeletonFilesSkipsSymlinkedFilemanagerTarget(): void
    {
        $this->pmssWriteRelativeFile($this->skelDir, 'www/filemanager.php', "before\n        ob_flush();\nafter\n");
        @mkdir(dirname($this->pmssUserHomePath($this->homeRoot, $this->user, 'www/filemanager.php')), 0755, true);

        $target = $this->pmssMakeTempPhpPath('pmss-user-filemanager-', 'patch-symlink-target');
        $link = $this->pmssUserHomePath($this->homeRoot, $this->user, 'www/filemanager.php');
        file_put_contents($target, "before\n        ob_flush();\nafter\n");
        @symlink($target, $link);

        \pmssUserApplySkeletonFiles($this->pmssUserHomeContext($this->homeRoot, $this->user));

        $this->assertEquals("before\n        ob_flush();\nafter\n", (string) file_get_contents($target));
    }

}
