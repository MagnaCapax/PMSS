<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise the CLI, real directory preparation and rendering with isolated I/O. */
class LighttpdConfigRefreshResultTest extends TestCase
{
    public function testPreparationFailuresStillInstallCurrentConfigAndReturnFailure(): void
    {
        foreach (['public-link', 'dangling-public', 'lighttpd-link', 'custom-directory'] as $kind) {
            $result = $this->refreshFixture($kind);
            $this->assertSame(1, $result['rc'], $kind);
            $this->assertSame(['alice', 'bob'], $result['attempted'], 'later accounts must still run');
            $this->assertStringContainsString('Password protected area (alice)', $result['config']);
            $this->assertStringContainsString('/user-alice/', $result['config']);
            $this->assertSame([['root', 0644]], $result['writes']);
            $this->assertSame([], $result['auxiliary'], 'no runtime writes after refused preparation');
            $this->assertFalse($result['linkChanged']);
            $this->assertSame(['.', '..'], $result['elsewhere']);
            $this->pmssAssertStringNotContainsString('lighttpd configured', $result['output']);
        }
    }

    public function testCompleteAccountReportsSuccess(): void
    {
        $result = $this->refreshFixture('ready');
        $this->assertSame(0, $result['rc']);
        $this->assertStringContainsString('lighttpd configured (port=31234', $result['output']);
        $this->assertTrue(in_array('php.ini', $result['auxiliary'], true));
    }

    public function testConfigAndPhpIniWriteFailuresReturnFailure(): void
    {
        foreach (['config-write', 'php-write'] as $kind) {
            $result = $this->refreshFixture($kind);
            $this->assertSame(1, $result['rc']);
            $this->pmssAssertStringNotContainsString('lighttpd configured', $result['output']);
        }
    }

    public function testIneligibleAccountSucceedsWithoutRendering(): void
    {
        $result = $this->refreshFixture('ineligible');
        $this->assertSame(0, $result['rc']);
        $this->assertSame(null, $result['config']);
        $this->assertSame([], $result['writes']);
    }

    private function refreshFixture(string $kind): array
    {
        $home = $this->pmssMakeTempDir('pmss-lighttpd-refresh-');
        mkdir($home.'/www', 0755);
        mkdir($home.'/elsewhere', 0700);
        file_put_contents($home.'/.rtorrent.rc', 'fixture');
        if (in_array($kind, ['public-link', 'dangling-public', 'lighttpd-link'], true)) {
            symlink($kind === 'dangling-public' ? $home.'/missing' : $home.'/elsewhere',
                $home.($kind === 'lighttpd-link' ? '/.lighttpd' : '/www/public'));
        } elseif ($kind === 'custom-directory') {
            mkdir($home.'/.lighttpd/custom', 0755, true);
        }
        $script = <<<'PHP'
namespace LighttpdRefreshFixture;
class UserConfigStore { public function get($user) { return []; } }
function pmssValidateUsername($user) { return $user === 'alice' || $user === 'bob'; }
function pmssShouldConfigureLighttpdForHome($home) { return $GLOBALS['kind'] !== 'ineligible'; }
function pmssPrepareLighttpdUserDirectories($user, $home, $deflate, &$reason) {
    $GLOBALS['attempted'][] = $user;
    // The second account succeeds without overwriting the first account's result.
    if ($user === 'bob') return true;
    return \pmssPrepareLighttpdUserDirectories($user, $GLOBALS['home'], $deflate, $reason);
}
function pmssReadRegularFileInt($path) { return 31234; }
function pmssLighttpdProxyPortsEnsure($paths) { return ['rclone' => 31235, 'qbittorrent' => 31236]; }
function pmssLighttpdDockerClientPort($user, $app) { return null; }
function pmssReadUserSlicePropertiesByUsername($user, $keys) { return []; }
function pmssLighttpdResourcePlan($props, $defaults, $config) {
    return ['memoryLimit' => 256, 'maxProcs' => 1, 'children' => 6, 'cpuQuotaPercent' => 100];
}
function pmssUserPanelSessionLoginEnabledFromPayload($config) { return false; }
function pmssLighttpdPanelSessionLoginOptions(...$args) { return []; }
function pmssWriteUserFile($path, $content, $owner, $mode) {
    if (strpos($path, '/bob/') !== false) return true;
    $GLOBALS['writes'][] = [$owner, $mode];
    if ($GLOBALS['kind'] === 'config-write') return false;
    return \file_put_contents($GLOBALS['home'].'/.lighttpd.conf', $content) === strlen($content);
}
function pmssEnsureWebdavLockDatabase($user, $home) { if ($user === 'alice') $GLOBALS['auxiliary'][] = 'webdav'; }
function pmssMediaStackPortsEnsure($user, $home) { if ($user === 'alice') $GLOBALS['auxiliary'][] = 'media-ports'; return []; }
function pmssLighttpdWriteManagedProxyFragment($app, $user, ...$args) { if ($user === 'alice') $GLOBALS['auxiliary'][] = 'proxy'; }
function pmssLighttpdDelugeWebPortResolve(...$args) { return null; }
function pmssLighttpdSyncPhpIni($path, $user, $memory, &$reason) {
    if ($user === 'bob') return true;
    $GLOBALS['auxiliary'][] = 'php.ini';
    return $GLOBALS['kind'] !== 'php-write';
}
function file_exists($path) {
    return strpos($path, '/etc/seedbox/runtime/ports') === 0 || $path === '/root/backups' || \file_exists($path);
}
function is_dir($path) { return $path === '/etc/seedbox/runtime/ports' ? false : \is_dir($path); }
function is_readable($path) { return false; }
function file_get_contents($path) { return \file_get_contents($GLOBALS['template']); }
function glob($pattern) { return []; }
function exec(...$args) { throw new \RuntimeException('Unexpected external command'); }
function pmssDetectDistro() { return ['version' => 11]; }
function pmssManagedUsersSelectFromCommand(...$args) { return ['exitCode' => 0, 'users' => ['alice', 'bob']]; }
function pmssRunCliEntrypointWithArgv(...$args) {}
PHP;
        $script .= '$GLOBALS["home"] = '.var_export($home, true).';';
        $script .= '$GLOBALS["kind"] = '.var_export($kind, true).';';
        $script .= '$GLOBALS["template"] = '.var_export($this->pmssRepoPath('etc/seedbox/config/template.lighttpd'), true).';';
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/userConfigApply.php', 'LighttpdRefreshFixture');
        // The CLI has a shebang, unlike the libraries accepted by the shared helper.
        $path = $this->pmssRepoPath('scripts/util/userConfigLighttpd.php');
        $source = str_replace(['__DIR__', '__FILE__'], [var_export(dirname($path), true), var_export($path, true)], file_get_contents($path));
        $script .= 'eval('.var_export('namespace LighttpdRefreshFixture;'.substr($source, strpos($source, '<?php') + 5), true).');';
        $script .= <<<'PHP'
$GLOBALS['attempted'] = $GLOBALS['writes'] = $GLOBALS['auxiliary'] = [];
\define('PMSS_TEST_MODE', true);
\putenv('PMSS_TEST_MODE=1');
\ob_start();
$rc = pmssUserConfigLighttpdMain(['userConfigLighttpd.php']);
$output = \ob_get_clean();
// Ignore the successful second account's auxiliary activity and success line.
echo json_encode(['rc' => $rc, 'attempted' => $GLOBALS['attempted'], 'writes' => $GLOBALS['writes'],
    'config' => \is_file($GLOBALS['home'].'/.lighttpd.conf') ? \file_get_contents($GLOBALS['home'].'/.lighttpd.conf') : null,
    'auxiliary' => $GLOBALS['auxiliary'], 'output' => preg_replace('/^\[user:bob\].*\n/m', '', $output),
    'linkChanged' => in_array($GLOBALS['kind'], ['public-link', 'dangling-public', 'lighttpd-link'], true)
        && !\is_link($GLOBALS['home'].($GLOBALS['kind'] === 'lighttpd-link' ? '/.lighttpd' : '/www/public')),
    'elsewhere' => \scandir($GLOBALS['home'].'/elsewhere')]);
PHP;
        return $this->pmssRunInlinePhpJson($script);
    }
}
