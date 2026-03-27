<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FilemanagerSkeletonContractTest extends TestCase
{
    public function testSkeletonUsesCurrentFrontendAssetPins(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/filemanager.php');

        $this->assertStringContainsAllStrings(
            [
                'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.slim.min.js',
                'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js',
                'https://cdn.datatables.net/2.0.8/js/dataTables.min.js',
            ],
            $source,
            'Missing filemanager asset pin: '
        );
        $this->pmssAssertStringNotContainsString('https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/', $source);
        $this->pmssAssertStringNotContainsString('https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js', $source);
    }

    public function testSkeletonKeepsDownloadHeaderAndRangeFixes(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/filemanager.php');

        $this->assertStringContainsAllStrings(
            [
                'header("Content-Disposition: $contentDisposition;filename=\\"$fileName\\"");',
                '        $range = str_replace("-", "", $range);',
                '        @ob_flush();',
            ],
            $source,
            'Missing filemanager download fragment: '
        );
        $this->pmssAssertStringNotContainsString('strstr($_SERVER[\'HTTP_USER_AGENT\'], "MSIE")', $source);
        $this->pmssAssertStringNotContainsString("\n        ob_flush();\n", $source);
    }
}
