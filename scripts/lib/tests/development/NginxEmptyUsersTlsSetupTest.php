<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Exercise real zero-user TLS setup with in-memory host files (Refs #942). */
class NginxEmptyUsersTlsSetupTest extends TestCase
{
    /** The complete file set required by the existing certificate guard. */
    private function certificatePaths(): array
    {
        return [
            '/etc/letsencrypt/live/node.example.test/fullchain.pem',
            '/etc/letsencrypt/live/node.example.test/privkey.pem',
            '/etc/letsencrypt/options-ssl-nginx.conf',
            '/etc/letsencrypt/ssl-dhparams.pem',
        ];
    }

    /** Keep template rendering real while intercepting host I/O and activation. */
    private function runSetup(array $states = [], int $configTestRc = 0, int $runs = 1): array
    {
        $script = 'namespace NginxEmptyUsersTlsFixture;'
            .' $GLOBALS["repo"] = '.var_export($this->pmssRepoPath(''), true).';'
            .' $GLOBALS["states"] = '.var_export(array_replace(array_fill_keys($this->certificatePaths(), 'readable'), $states), true).';'
            .' $GLOBALS["configTestRc"] = '.$configTestRc.';';
        $script .= <<<'PHP'
// Service paths stay in memory; only repository templates are read from disk.
$GLOBALS['files'] = [
    '/etc/nginx/sites-available/default' => 'stale snakeoil site',
    '/etc/nginx/sites-enabled/default' => 'stale snakeoil site',
    '/etc/nginx/ssl/nginx.crt' => 'existing self-signed certificate',
];
$GLOBALS['tests'] = [];
function file_get_contents($path) {
    if (strpos($path, '/etc/seedbox/config/template.') !== 0) {
        throw new \RuntimeException('Unexpected fixture read: '.$path);
    }
    return \file_get_contents($GLOBALS['repo'].substr($path, 1));
}
function file_exists($path) {
    return isset($GLOBALS['states'][$path])
        ? $GLOBALS['states'][$path] !== 'missing' : isset($GLOBALS['files'][$path]);
}
function is_readable($path) { return ($GLOBALS['states'][$path] ?? '') === 'readable'; }
function is_file($path) { return false; }
function is_link($path) { return false; }
function copy($source, $target) { return true; }
// A regression must fail the fixture rather than mutate a host or launch a command.
function passthru($command) { throw new \RuntimeException('Unexpected command'); }
function symlink($target, $path) { throw new \RuntimeException('Unexpected symlink'); }
function unlink($path) { throw new \RuntimeException('Unexpected removal'); }
function glob($pattern) { throw new \RuntimeException('Unexpected config scan'); }
function pmssCreateNginxConfigPruneOrphans($users, $ctx) {
    throw new \RuntimeException('Unexpected orphan pruning');
}
function pmssDirEnsureExists($path, $mode) { return true; }
function pmssEnsureSafeDir($path, $mode) { return true; }
function pmssBackupCriticalConfig($service, $path) {}
function pmssFileWriteComplete($path, $content) {
    $GLOBALS['files'][$path] = $content;
    return true;
}
function pmssHostnameRead() { return 'node.example.test'; }
function pmssManagedUsersSelectFromCommand($command, $user, $options) {
    return ['exitCode' => 0, 'username' => '', 'users' => []];
}
function pmssCreateNginxConfigTestAndMaybeRestart($restart, $reload = false) {
    $GLOBALS['tests'][] = [
        $restart, $reload, $GLOBALS['files']['/etc/nginx/sites-available/default'],
        $GLOBALS['files']['/etc/nginx/sites-enabled/default'],
    ];
    return $GLOBALS['configTestRc'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/nginxConfig/setup.php', 'NginxEmptyUsersTlsFixture');
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/nginxConfig/main.php', 'NginxEmptyUsersTlsFixture');
        $script .= '\ob_start(); $results = []; for ($i = 0; $i < '.$runs.'; ++$i) {'
            .' $results[] = pmssCreateNginxConfigMain(["createNginxConfig.php", "--reload"]); } \ob_end_clean();'
            .' echo json_encode(["results" => $results, "tests" => $GLOBALS["tests"]]);';
        return $this->pmssRunInlinePhpJson($script);
    }

    public function testCompleteCertificateReplacesSnakeoilBeforeValidationAndReload(): void
    {
        $result = $this->runSetup();
        $this->assertSame([0], $result['results']);
        $this->assertSame(1, count($result['tests']));
        [$restart, $reload, $site, $enabled] = $result['tests'][0];
        $this->assertSame([false, true], [$restart, $reload]);
        $this->assertSame($site, $enabled);
        foreach ($this->certificatePaths() as $path) {
            $this->assertTrue(strpos($site, $path) !== false, $path);
        }
        $this->assertFalse(strpos($site, '/etc/nginx/ssl/nginx.crt') !== false);
        $this->assertFalse(strpos($site, '||SERVER_HOSTNAME||') !== false);
    }

    public function testEachMissingRequiredFileKeepsSelfSignedFallback(): void
    {
        foreach ($this->certificatePaths() as $path) {
            $result = $this->runSetup([$path => 'missing']);
            $this->assertSame([0], $result['results']);
            $site = $result['tests'][0][2];
            $this->assertTrue(strpos($site, '/etc/nginx/ssl/nginx.crt') !== false, $path);
            $this->assertFalse(strpos($site, '/etc/letsencrypt/') !== false, $path);
        }
    }

    public function testEachUnreadableRequiredFileKeepsSelfSignedFallback(): void
    {
        foreach ($this->certificatePaths() as $path) {
            $result = $this->runSetup([$path => 'unreadable']);
            $this->assertSame([0], $result['results']);
            $site = $result['tests'][0][2];
            $this->assertTrue(strpos($site, '/etc/nginx/ssl/nginx.crt') !== false, $path);
            $this->assertFalse(strpos($site, '/etc/letsencrypt/') !== false, $path);
        }
    }

    public function testConfigValidationFailurePropagatesOnZeroUserNode(): void
    {
        $result = $this->runSetup([], 1);
        $this->assertSame([1], $result['results']);
        $this->assertSame(1, count($result['tests']));
    }

    public function testRepeatedZeroUserRefreshKeepsCertificateWiringStable(): void
    {
        $result = $this->runSetup([], 0, 2);
        $this->assertSame([0, 0], $result['results']);
        $this->assertSame(2, count($result['tests']));
        $this->assertSame($result['tests'][0], $result['tests'][1]);
    }
}
