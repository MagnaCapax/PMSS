<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/filemanagerActionScan.php';

class FilemanagerSkeletonContractTest extends TestCase
{
    public function testUploadPathStaysInsideTheCurrentFolder(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/filemanager.php');
        $this->assertStringContainsAllStrings(['$relPath = fm_clean_path(isset($_REQUEST[\'fullpath\'])',
            '$fullPath = $path . \'/\' . $relPath;'], $source);
        $this->pmssAssertStringNotContainsString('$path . \'/\' . $_REQUEST[\'fullpath\']', $source);
        $this->pmssAssertStringNotContainsString('umask(0)', $source, 'upload folders keep the account umask');
    }

    public function testChangingBranchesRequireSessionToken(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/filemanager.php');
        $this->assertSame([], pmssFilemanagerUnguardedWrites($source));
        $this->assertStringContainsAllStrings(['hash_equals(', "formData.append('action_token'"], $source);
    }

    public function testWriteScannerRejectsMissingLateAndSiblingGuards(): void
    {
        foreach ([
            ['if(isset($_POST["new"])) { file_put_contents("fixture", "changed"); }', 1],
            ['if(isset($_POST["new"])) { fm_action_required(); file_put_contents("fixture", "changed"); }', 0],
            ['if(isset($_POST["new"])) { file_put_contents("fixture", "changed"); fm_action_required(); }', 1],
            ['if(isset($_POST["old"])) { fm_action_required(); } if(isset($_GET["new"])) { unlink("fixture"); }', 1],
            ['if(isset($_FILES["new"])) { /* fm_action_required(); */ COPY("a", "b"); }', 1],
            ['if(file_put_contents($_POST["path"], "changed")) { fm_action_required(); }', 1],
            ['function helper($path) { file_put_contents($path, "changed"); }', 0],
        ] as [$code, $count]) {
            $this->assertSame($count, count(pmssFilemanagerUnguardedWrites('<?php '.$code)), $code);
        }
    }

    public function testWriteScannerCatchesDispatchAndReadOnlyRegressions(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/filemanager.php');
        foreach ([
            str_replace('fm_action_required();', '', $source),
            str_replace("is_string(\$_POST['type'] ?? null)", 'true', $source),
            str_replace("\$_POST['type'] ===", "\$_POST['type'] ==", $source),
            str_replace('//search : get list of files from the current folder',
                'if ($_POST[\'type\'] === "search") { unlink("fixture"); }', $source),
            $source.'<?php if (isset($_GET["new_action"])) { rename("a", "b"); } ?>',
        ] as $changed) {
            $this->assertTrue(pmssFilemanagerUnguardedWrites($changed) !== []);
        }
    }

    public function testWriteScannerCoversFileAndArchiveOperations(): void
    {
        foreach (['copy', 'rename', 'fm_rdelete', 'fm_rename', 'fm_rcopy', 'fm_mkdir', 'mkdir',
            'move_uploaded_file', 'chmod', '$archive->extractTo', '$config->save', '$archive->create',
            'file_put_contents', 'unlink', 'fm_remote_url_download', 'fwrite'] as $call) {
            foreach (['', 'fm_action_required();'] as $guard) {
                $code = '<?php if (isset($_REQUEST["action"])) { '.$guard.$call.'("fixture"); }';
                $this->assertSame($guard === '' ? 1 : 0, count(pmssFilemanagerUnguardedWrites($code)), $call);
            }
        }
        foreach (['w', 'a', 'a+', 'r'] as $mode) {
            $code = '<?php if (isset($_POST["action"])) { fopen("fixture", "'.$mode.'"); }';
            $this->assertSame($mode === 'r' ? 0 : 1, count(pmssFilemanagerUnguardedWrites($code)), $mode);
        }
    }

    public function testDeleteRejectsGetAndInvalidPostButAcceptsValidPost(): void
    {
        $home = $this->pmssMakeTempDir('pmss-filemanager-action-');
        $source = $this->pmssRepoPath('etc/skel/www/filemanager.php');
        foreach (['GET', 'POST-invalid', 'POST-valid'] as $case) {
            file_put_contents($home.'/victim', 'keep');
            $method = $case === 'GET' ? 'GET' : 'POST';
            $token = $case === 'POST-valid' ? str_repeat('a', 64) : 'invalid';
            $script = <<<'PHP'
define('FM_EMBED', true);
$_SERVER = ['USER' => %s, 'HTTP_HOST' => 'localhost', 'PHP_SELF' => '/filemanager.php',
    'SCRIPT_NAME' => '/filemanager.php', 'REQUEST_URI' => '/filemanager.php?p=', 'REQUEST_METHOD' => %s];
$_SESSION = ['filemanager' => ['action_token' => str_repeat('a', 64)]];
$_GET = ['p' => '']; $_POST = []; $_FILES = $_REQUEST = [];
if (%s === 'GET') $_GET['del'] = 'victim';
else $_POST = ['del' => 'victim', 'action_token' => %s];
ob_start();
register_shutdown_function(function () { ob_end_clean(); echo json_encode([http_response_code(), file_exists(%s)]); });
require %s;
PHP;
            $run = $this->pmssExecInlinePhp(sprintf(
                $script, var_export('../'.ltrim($home, '/'), true), var_export($method, true),
                var_export($method, true), var_export($token, true), var_export($home.'/victim', true), var_export($source, true)
            ));
            $this->assertSame(0, $run['rc'], $run['output']);
            $result = json_decode($run['output'], true);
            $this->assertTrue(is_array($result), $run['output']);
            $this->assertSame($case === 'POST-valid', !$result[1], $case);
            if ($case !== 'POST-valid') $this->assertSame(403, $result[0]);
        }
    }

    public function testChangingRoutesRequireTokenAndReadOnlyAjaxRemainsAvailable(): void
    {
        $home = $this->pmssMakeTempDir('pmss-filemanager-routes-');
        file_put_contents($home.'/victim', 'keep');
        $source = $this->pmssRepoPath('etc/skel/www/filemanager.php');
        $actions = [
            'logout' => ['logout' => '1'],
            'delete' => ['del' => 'victim'],
            'new' => ['new' => 'created', 'type' => 'file'],
            'copy' => ['copy' => 'victim', 'finish' => '1'],
            'group-copy' => ['file' => ['victim'], 'copy_to' => 'other', 'finish' => '1'],
            'rename' => ['ren' => 'victim', 'to' => 'renamed'],
            'file-upload' => [],
            'group-delete' => ['group' => '1', 'delete' => '1', 'file' => ['victim']],
            'archive' => ['group' => '1', 'zip' => '1', 'file' => ['victim']],
            'unzip' => ['unzip' => 'victim'],
            'chmod' => ['chmod' => 'victim', 'ur' => '1'],
            'ajax-save' => ['ajax' => '1', 'type' => 'save', 'content' => 'changed'],
            'ajax-backup' => ['ajax' => '1', 'type' => 'backup', 'file' => 'victim'],
            'ajax-settings' => ['ajax' => '1', 'type' => 'settings'],
            'ajax-remote-upload' => ['ajax' => '1', 'type' => 'upload'],
            'savedata' => ['savedata' => 'changed'],
            'ajax-unknown' => ['ajax' => '1', 'type' => 'future-action'],
            'ajax-json-true' => [],
            'ajax-json-false' => [],
            'ajax-json-number' => [],
            'ajax-json-array' => [],
            'ajax-json-null' => [],
            'readonly-pwdhash' => ['ajax' => '1', 'type' => 'pwdhash', 'inputPassword2' => ''],
            'readonly-search' => ['ajax' => '1', 'type' => 'search', 'path' => '', 'content' => 'no-match'],
        ];
        $script = <<<'PHP'
define('FM_EMBED', true);
$_SERVER = ['USER' => %s, 'HTTP_HOST' => 'localhost', 'PHP_SELF' => '/filemanager.php',
    'SCRIPT_NAME' => '/filemanager.php', 'REQUEST_URI' => '/filemanager.php?p=', 'REQUEST_METHOD' => 'POST'];
$_SESSION = ['filemanager' => ['action_token' => str_repeat('a', 64)]];
$_GET = ['p' => '', 'edit' => 'victim']; $_POST = %s; $_FILES = %s; $_REQUEST = [];
$GLOBALS['fmTestInput'] = %s;
// Feed the actual php://input read in an isolated CLI process, without a listener.
class FM_TestInput {
    public $context;
    private $offset = 0;
    public function stream_open($path, $mode, $options, &$openedPath) { return $path === 'php://input'; }
    public function stream_read($count) {
        $chunk = substr($GLOBALS['fmTestInput'], $this->offset, $count);
        $this->offset += strlen($chunk);
        return $chunk;
    }
    public function stream_eof() { return $this->offset >= strlen($GLOBALS['fmTestInput']); }
    public function stream_stat() { return []; }
}
if ($GLOBALS['fmTestInput'] !== '') {
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'FM_TestInput');
}
ob_start();
register_shutdown_function(function () { ob_end_clean(); echo json_encode([http_response_code(), file_get_contents(%s)]); });
require %s;
PHP;
        foreach ($actions as $name => $post) {
            $files = $name === 'file-upload' ? ['file' => ['name' => 'upload']] : [];
            $types = ['ajax-json-true' => true, 'ajax-json-false' => false,
                'ajax-json-number' => 1, 'ajax-json-array' => [], 'ajax-json-null' => null];
            $body = array_key_exists($name, $types)
                ? json_encode(['ajax' => 1, 'type' => $types[$name], 'x' => 'save', 'content' => 'changed']) : '';
            $run = $this->pmssExecInlinePhp(sprintf(
                $script, var_export('../'.ltrim($home, '/'), true), var_export($post, true),
                var_export($files, true), var_export($body, true), var_export($home.'/victim', true), var_export($source, true)
            ));
            $this->assertSame(0, $run['rc'], $name.': '.$run['output']);
            $result = json_decode($run['output'], true);
            if (strpos($name, 'readonly-') === 0) {
                $this->assertTrue(is_array($result), $run['output']);
                $this->assertTrue($result[0] !== 403, $name);
                $this->assertSame('keep', $result[1], $name);
            } else {
                $this->assertSame([403, 'keep'], $result, $name);
            }
        }
    }

    public function testCreateRenameAndSavedataStillWorkWithSessionToken(): void
    {
        $home = $this->pmssMakeTempDir('pmss-filemanager-valid-');
        $source = $this->pmssRepoPath('etc/skel/www/filemanager.php');
        $script = <<<'PHP'
define('FM_EMBED', true);
$_SERVER = ['USER' => %s, 'HTTP_HOST' => 'localhost', 'PHP_SELF' => '/filemanager.php',
    'SCRIPT_NAME' => '/filemanager.php', 'REQUEST_URI' => '/filemanager.php?p=', 'REQUEST_METHOD' => 'POST'];
$_SESSION = ['filemanager' => ['action_token' => str_repeat('a', 64)]];
$_GET = ['p' => '', 'edit' => 'renamed']; $_POST = %s; $_POST['action_token'] = str_repeat('a', 64);
$_FILES = $_REQUEST = [];
ob_start();
register_shutdown_function(function () { ob_end_clean(); });
require %s;
PHP;
        foreach ([
            ['new' => 'created', 'type' => 'file'],
            ['ren' => 'created', 'to' => 'renamed'],
            ['savedata' => 'updated through the legacy editor'],
        ] as $post) {
            $run = $this->pmssExecInlinePhp(sprintf(
                $script, var_export('../'.ltrim($home, '/'), true), var_export($post, true), var_export($source, true)
            ));
            $this->assertSame(0, $run['rc'], $run['output']);
        }
        $this->assertFalse(file_exists($home.'/created'));
        $this->assertTrue(is_file($home.'/renamed'));
        $this->assertSame('updated through the legacy editor', file_get_contents($home.'/renamed'));
    }

    public function testSettingsSurviveSkeletonRefresh(): void
    {
        $home = $this->pmssMakeTempDir('pmss-filemanager-settings-');
        $source = $this->pmssRepoPath('etc/skel/www/filemanager.php');
        $local = $home.'/filemanager.php';
        $this->assertTrue(copy($source, $local));
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
$settings = %s;
$source = %s;
$local = %s;
$defaults = (new FM_Config())->data;
$createdOnRead = file_exists($settings);
$cfg->data['show_hidden'] = true;
$cfg->data['calc_folder'] = true;
$saved = $cfg->save();
$sourceUnchanged = hash_file('sha1', $local) === hash_file('sha1', $source);
copy($source, $local);
$afterRefresh = (new FM_Config())->data;
file_put_contents($settings, '{"show_hidden":true}');
$partial = (new FM_Config())->data;
file_put_contents($settings, '{invalid');
$malformed = (new FM_Config())->data;
echo json_encode(compact('defaults', 'createdOnRead', 'saved', 'sourceUnchanged', 'afterRefresh', 'partial', 'malformed'));
PHP;
        $script = sprintf(
            $script,
            var_export('../'.ltrim($home, '/'), true),
            var_export($local, true),
            var_export($home.'/.config/filemanager-settings.json', true),
            var_export($source, true),
            var_export($local, true)
        );
        $run = $this->pmssExecInlinePhp($script);
        $this->assertSame(0, $run['rc'], 'Filemanager settings probe failed: '.$run['output']);
        $result = json_decode($run['output'], true);
        $this->assertTrue(is_array($result), 'Filemanager settings probe did not return JSON: '.$run['output']);
        $this->assertSame(['lang', 'error_reporting', 'show_hidden', 'hide_Cols', 'calc_folder'], array_keys($result['defaults']));
        $this->assertFalse($result['createdOnRead']);
        $this->assertTrue($result['saved']);
        $this->assertTrue($result['sourceUnchanged']);
        $this->assertTrue($result['afterRefresh']['show_hidden']);
        $this->assertTrue($result['afterRefresh']['calc_folder']);
        $this->assertSame($result['defaults']['calc_folder'], $result['partial']['calc_folder']);
        $this->assertSame($result['defaults'], $result['malformed']);
    }

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
        $run = $this->pmssExecInlinePhp($script);
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
