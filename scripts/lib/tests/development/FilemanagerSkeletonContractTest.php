<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FilemanagerSkeletonContractTest extends TestCase
{
    private function runRemoteUrlProbe(): array
    {
        $root = $this->pmssMakeTempDir('pmss-filemanager-url-');
        $source = $this->pmssRepoPath('etc/skel/www/filemanager.php');
        $user = '../'.ltrim($root, '/');
        $script = <<<'PHP'
define('FM_EMBED', true);
$_SERVER = array(
    'USER' => %s,
    'HTTP_HOST' => 'localhost',
    'PHP_SELF' => '/filemanager.php',
    'SCRIPT_NAME' => '/filemanager.php',
    'REQUEST_URI' => '/filemanager.php?p=',
);
$_GET = array('p' => '');
$_POST = $_FILES = $_REQUEST = array();
ob_start();
require %s;
ob_end_clean();
$tempFile = tempnam(sys_get_temp_dir(), 'fm-url-test-');
$fileinfo = new stdClass();
$error = false;
$result = array(
    'addresses' => array(
        'public-v4' => fm_remote_url_address_is_public('8.8.8.8'),
        'private-v4' => fm_remote_url_address_is_public('10.0.0.1'),
        'loopback-v4' => fm_remote_url_address_is_public('127.0.0.1'),
        'link-local-v4' => fm_remote_url_address_is_public('169.254.1.1'),
        'carrier-v4' => fm_remote_url_address_is_public('100.64.0.1'),
        'documentation-v4' => fm_remote_url_address_is_public('192.0.2.1'),
        'multicast-v4' => fm_remote_url_address_is_public('224.0.0.1'),
        'public-v6' => fm_remote_url_address_is_public('2606:4700:4700::1111'),
        'loopback-v6' => fm_remote_url_address_is_public('::1'),
        'private-v6' => fm_remote_url_address_is_public('fc00::1'),
        'link-local-v6' => fm_remote_url_address_is_public('fe80::1'),
        'multicast-v6' => fm_remote_url_address_is_public('ff02::1'),
        'documentation-v6' => fm_remote_url_address_is_public('2001:db8::1'),
        'mapped-v6' => fm_remote_url_address_is_public('::ffff:127.0.0.1'),
        'invalid' => fm_remote_url_address_is_public('not-an-address'),
    ),
    'targets' => array(
        'public-v4' => fm_remote_url_target('http://8.8.8.8/file'),
        'public-v6' => fm_remote_url_target('https://[2606:4700:4700::1111]/file'),
        'custom-port' => fm_remote_url_target('https://8.8.8.8:8443/file'),
        'private-v4' => fm_remote_url_target('http://10.0.0.1/file'),
        'private-v6' => fm_remote_url_target('http://[::1]/file'),
        'credentials' => fm_remote_url_target('https://user:pass@8.8.8.8/file'),
        'wrong-scheme' => fm_remote_url_target('ftp://8.8.8.8/file'),
        'backslash' => fm_remote_url_target("http://8.8.8.8\\@127.0.0.1/file"),
        'invalid-host' => fm_remote_url_target('http://bad_host/file'),
    ),
    'blocked-download' => fm_remote_url_download('http://127.0.0.1/file', $tempFile, $fileinfo, $error),
    'blocked-error' => $error,
);
@unlink($tempFile);
echo json_encode($result);
PHP;
        $script = sprintf($script, var_export($user, true), var_export($source, true));
        $run = $this->pmssExecShellCommand(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script));
        $this->assertSame(0, $run['rc'], 'Filemanager helper probe failed: '.$run['output']);
        $result = json_decode($run['output'], true);
        $this->assertTrue(is_array($result), 'Filemanager helper probe did not return JSON: '.$run['output']);
        return $result;
    }

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

    public function testSkeletonPreservesPerUserUrlBaseAfterMappedRootSelection(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'etc/skel/www/filemanager.php',
            [
                "if (isset(\$_SESSION[FM_SESSION_ID]['logged']) && !empty(\$directories_users[\$_SESSION[FM_SESSION_ID]['logged']])) {",
                "if (\$root_url === '') {\n    \$root_url = ltrim((string) dirname(\$_SERVER['SCRIPT_NAME']), '/');\n}",
                '$root_url = fm_clean_path($root_url);',
                "defined('FM_ROOT_URL') || define('FM_ROOT_URL'",
            ],
            'Missing filemanager URL-base fragment: ',
            'Filemanager URL-base ordering changed at: '
        );
    }

    public function testSkeletonRemoteUrlAddressValidationRejectsNonPublicRanges(): void
    {
        $addresses = $this->runRemoteUrlProbe()['addresses'];
        $this->assertTrue($addresses['public-v4']);
        $this->assertTrue($addresses['public-v6']);
        foreach ($addresses as $name => $allowed) {
            if ($name === 'public-v4' || $name === 'public-v6') {
                continue;
            }
            $this->assertFalse($allowed, 'Expected non-public address to be rejected: '.$name);
        }
    }

    public function testSkeletonRemoteUrlTargetParsingFailsClosed(): void
    {
        $targets = $this->runRemoteUrlProbe()['targets'];
        $this->assertSame('8.8.8.8', $targets['public-v4']['host']);
        $this->assertSame(80, $targets['public-v4']['port']);
        $this->assertSame(443, $targets['public-v6']['port']);
        $this->assertSame(8443, $targets['custom-port']['port']);
        $this->assertSame('8.8.8.8', $targets['credentials']['host']);
        foreach (['private-v4', 'private-v6', 'wrong-scheme', 'backslash', 'invalid-host'] as $name) {
            $this->assertFalse($targets[$name], 'Expected unsafe URL to be rejected: '.$name);
        }
    }

    public function testSkeletonRemoteUrlDownloadRejectsLoopbackBeforeFetch(): void
    {
        $probe = $this->runRemoteUrlProbe();
        $this->assertFalse($probe['blocked-download']);
        $this->assertSame('Remote URL is not allowed', $probe['blocked-error']['message']);
    }

    public function testSkeletonRemoteUrlDownloadPinsEachValidatedHop(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'etc/skel/www/filemanager.php',
            [
                'CURLOPT_FOLLOWLOCATION => false',
                'CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS',
                "CURLOPT_PROXY => ''",
                '$options[CURLOPT_RESOLVE]',
                "isset(\$curlInfo['primary_ip'])",
                "isset(\$curlInfo['redirect_url'])",
                '$target = fm_remote_url_target($url);',
            ],
            'Missing filemanager remote-fetch safety fragment: '
        );
        $this->pmssAssertRepoFileNotContainsStrings(
            'etc/skel/www/filemanager.php',
            ['CURLOPT_FOLLOWLOCATION'.', true', 'copy($url, $temp'.'_file']
        );
    }
}
