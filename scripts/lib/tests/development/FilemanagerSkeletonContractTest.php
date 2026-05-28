<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FilemanagerSkeletonContractTest extends TestCase
{
    public function testSkeletonUsesCurrentFrontendAssetPins(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/filemanager.php',
            [
                'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.slim.min.js',
                'https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js',
                'https://cdn.datatables.net/2.0.8/js/dataTables.min.js',
            ],
            'Missing filemanager asset pin: '
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            'etc/skel/www/filemanager.php',
            ['https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/', 'https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js']
        );
    }

    public function testSkeletonKeepsDownloadHeaderAndRangeFixes(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/filemanager.php',
            [
                'header("Content-Disposition: $contentDisposition;filename=\\"$fileName\\"");',
                '        $range = str_replace("-", "", $range);',
                '        @ob_flush();',
            ],
            'Missing filemanager download fragment: '
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            'etc/skel/www/filemanager.php',
            ['strstr($_SERVER[\'HTTP_USER_AGENT\'], "MSIE")', "\n        ob_flush();\n"]
        );
    }
}
