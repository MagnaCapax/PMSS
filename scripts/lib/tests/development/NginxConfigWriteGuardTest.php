<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/nginxConfig/userConfigsGenerate.php';
require_once dirname(__DIR__, 2).'/user/identity.php';
require_once __DIR__.'/../common/TestCase.php';

/**
 * Verify nginx config generation uses guarded file writes.
 */
class NginxConfigWriteGuardTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-nginx-config-write-']; }

    public function testWriteFileStoresContentWithManagedPermissions(): void
    {
        $path = $this->tempDir.'/conf.d/pmss-user-alice.conf';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssCreateNginxConfigWriteFile($path, "server {}\n", 'alice', 'public subdomain config'));
        $this->assertEquals("server {}\n", (string) file_get_contents($path));
        $this->assertEquals(0640, fileperms($path) & 0777);
    }

    public function testWriteFileReplacesExistingRegularFile(): void
    {
        $path = $this->tempDir.'/users/alice';
        $this->pmssWriteFile($path, "old\n");

        $this->assertTrue(\pmssCreateNginxConfigWriteFile($path, "new\n", 'alice', 'user config'));
        $this->assertEquals("new\n", (string) file_get_contents($path));
    }

    public function testWriteFileRejectsSymlinkTarget(): void
    {
        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real.conf', $this->tempDir.'/link.conf', "keep\n");

        $this->assertFalse(\pmssCreateNginxConfigWriteFile($linkPath, "replace\n", 'alice', 'user config'));
        $this->assertEquals("keep\n", (string) file_get_contents($realPath));
    }

    public function testWriteFileRejectsMissingParentDirectory(): void
    {
        $path = $this->tempDir.'/missing/pmss-user-alice.conf';

        $this->assertFalse(\pmssCreateNginxConfigWriteFile($path, "server {}\n", 'alice', 'public subdomain config'));
        $this->assertFalse(file_exists($path));
    }

    public function testRemoveFileDeletesRegularFile(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/users/alice', "old\n");

        $this->assertTrue(\pmssCreateNginxConfigRemoveFile($path, 'alice', 'stale user config'));
        $this->assertFalse(file_exists($path));
    }

    public function testRemoveFileTreatsMissingFileAsConverged(): void
    {
        $path = $this->tempDir.'/users/missing';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssCreateNginxConfigRemoveFile($path, 'alice', 'stale user config'));
        $this->assertFalse(file_exists($path));
    }

    public function testRemoveFileRejectsSymlinkTarget(): void
    {
        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real.conf', $this->tempDir.'/link.conf', "keep\n");

        $this->assertFalse(\pmssCreateNginxConfigRemoveFile($linkPath, 'alice', 'stale user config'));
        $this->assertEquals("keep\n", (string) file_get_contents($realPath));
        $this->assertTrue(is_link($linkPath), 'symlink target must remain untouched');
    }

    public function testRemoveFileRejectsDirectoryTarget(): void
    {
        $path = $this->tempDir.'/users/alice';
        @mkdir($path, 0755, true);

        $this->assertFalse(\pmssCreateNginxConfigRemoveFile($path, 'alice', 'stale user config'));
        $this->assertTrue(is_dir($path), 'directory target must remain untouched');
    }

    public function testReconcileRemovesOnlyObsoleteUserVariants(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainConfigDir' => $this->tempDir.'/conf.d', 'subdomainEnabled' => true];
        $paths = \pmssCreateNginxConfigManagedUserPaths('alice', $ctx);
        foreach ($paths as $path) $this->pmssWriteFile($path, "old\n");

        $this->assertTrue(\pmssCreateNginxConfigReconcileStaleUserFiles('alice', $ctx, [$paths['user'], $paths['public']]));
        $this->assertTrue(is_file($paths['user']));
        $this->assertTrue(is_file($paths['public']));
        $this->assertFalse(file_exists($paths['private']));
    }

    public function testOrphanPruneUsesSelectedUserSet(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainConfigDir' => $this->tempDir.'/conf.d', 'subdomainEnabled' => true];
        foreach (['alice', 'bob'] as $user) {
            foreach (\pmssCreateNginxConfigManagedUserPaths($user, $ctx) as $path) $this->pmssWriteFile($path, "managed\n");
        }

        $this->assertTrue(\pmssCreateNginxConfigPruneOrphans(['alice'], $ctx));
        foreach (\pmssCreateNginxConfigManagedUserPaths('alice', $ctx) as $label => $path) {
            $this->assertSame($label !== 'private', is_file($path));
        }
        foreach (\pmssCreateNginxConfigManagedUserPaths('bob', $ctx) as $path) $this->assertFalse(file_exists($path));
    }

    public function testOrphanPruneKeepsFilesOutsideGeneratedNameShapes(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainConfigDir' => $this->tempDir.'/conf.d', 'subdomainEnabled' => true];
        $kept = [
            $this->pmssWriteFile($ctx['nginxUsersDir'].'/README', "keep\n"),
            $this->pmssWriteFile($ctx['nginxUsersDir'].'/alice.backup', "keep\n"),
            $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-ALICE.conf', "keep\n"),
            $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-alice-extra.conf', "keep\n"),
        ];
        $orphan = $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-bob.conf', "remove\n");

        $this->assertTrue(\pmssCreateNginxConfigPruneOrphans([], $ctx));
        foreach ($kept as $path) $this->assertSame("keep\n", file_get_contents($path));
        $this->assertFalse(file_exists($orphan));
    }

    public function testOrphanPruneReportsFailedGlobScan(): void
    {
        $script = <<<'PHP'
namespace NginxPruneGlobFixture;
function glob($pattern) { return false; }
function pmssValidateUsername($user) { return preg_match('/^[a-z][a-z0-9]{0,7}$/D', $user) === 1; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/nginxConfig/userConfigsReconcile.php', 'NginxPruneGlobFixture');
        $script .= 'echo json_encode(["ok" => pmssCreateNginxConfigPruneOrphans([], ["subdomainEnabled" => true])]);';

        $this->assertSame(['ok' => false], $this->pmssRunInlinePhpJson($script));
    }

    public function testRetiredHashConfigPruneUsesExactGeneratedFilename(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainConfigDir' => $this->tempDir.'/conf.d', 'subdomainEnabled' => false];
        $generated = $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-alice-hash.conf', "old\n");
        $lookalike = $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-ALICE-hash.conf', "keep\n");
        $backup = $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-alice-hash.conf.bak', "keep\n");

        $this->assertTrue(\pmssCreateNginxConfigPruneOrphans(['alice'], $ctx));
        $this->assertFalse(file_exists($generated));
        $this->assertTrue(is_file($lookalike));
        $this->assertTrue(is_file($backup));
    }

    public function testGeneratorWriteFailurePreservesPriorTarget(): void
    {
        $homeBase = $this->tempDir.'/home';
        $runtimePortDir = $this->tempDir.'/ports';
        $nginxUsersDir = $this->tempDir.'/users';
        $this->pmssWriteFile($homeBase.'/alice/.rtorrent.rc', "schedule = test\n");
        $this->pmssWriteFile($homeBase.'/alice/.lighttpd.conf', "server.port = 12345\n");
        $this->pmssWriteFile($runtimePortDir.'/lighttpd-alice', "12345\n");
        $priorRoute = $this->pmssWriteFile($this->tempDir.'/prior-route', "old route\n");
        @mkdir($nginxUsersDir, 0755, true);
        if (!@symlink($priorRoute, $nginxUsersDir.'/alice')) throw new SkipTest('symlink unavailable');
        $ctx = ['nginxUsersDir' => $nginxUsersDir, 'homeBase' => $homeBase, 'runtimePortDir' => $runtimePortDir,
            'userTemplate' => 'proxy ##username ##serverPort', 'subdomainEnabled' => false, 'needsDelugeWebPort' => false];

        $this->assertSame(PMSS_NGINX_USER_CONFIG_WRITE_FAILED, \pmssCreateNginxConfigGenerateUser('alice', $ctx, false));
        $this->assertEquals("old route\n", (string) file_get_contents($priorRoute));
        $this->assertTrue(is_link($nginxUsersDir.'/alice'));
    }

    public function testActiveAndSuspendedGenerationKeepsRenderedRoutesAndCleanup(): void
    {
        $home = $this->tempDir.'/home/alice';
        $users = $this->tempDir.'/users';
        $conf = $this->tempDir.'/conf.d';
        $ports = $this->tempDir.'/ports';
        $this->pmssWriteFile($home.'/.rtorrent.rc', "schedule = test\n");
        $this->pmssWriteFile($home.'/.lighttpd.conf', "server.port = 12345\n");
        $this->pmssWriteFile($ports.'/lighttpd-alice', "12345\n");
        @mkdir($users, 0755, true);
        @mkdir($conf, 0755, true);
        $ctx = [
            'homeBase' => $this->tempDir.'/home', 'runtimePortDir' => $ports,
            'nginxUsersDir' => $users, 'subdomainConfigDir' => $conf,
            'subdomainEnabled' => true, 'subdomainBase' => 'example.test',
            'userTemplate' => 'active ##username ##serverPort',
            'suspendedTemplate' => 'suspended ##username',
            'publicSubdomainTemplate' => 'public ##host## ##port##',
            'publicSuspendedTemplate' => 'suspended public ##host##',
        ];
        $public = $conf.'/pmss-user-alice.conf';
        $private = $conf.'/pmss-user-alice-hash.conf';
        $this->pmssWriteFile($private, "stale\n");

        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('alice', $ctx, false));
        $this->assertSame('active alice 12345', file_get_contents($users.'/alice'));
        $this->assertSame('public alice.example.test 12345', file_get_contents($public));
        $this->assertFalse(file_exists($private));

        $ctx['userTemplate'] = false;
        $this->assertSame(PMSS_NGINX_USER_CONFIG_SKIPPED, \pmssCreateNginxConfigGenerateUser('alice', $ctx, false));
        $this->assertFalse(file_exists($users.'/alice'));
        $this->assertSame('public alice.example.test 12345', file_get_contents($public));

        @mkdir($home.'/www-disabled', 0755);
        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('alice', $ctx, false));
        $this->assertSame('suspended alice', file_get_contents($users.'/alice'));
        $this->assertSame('suspended public alice.example.test', file_get_contents($public));
        $this->assertFalse(file_exists($private));

        $ctx['suspendedTemplate'] = false;
        $this->assertSame(PMSS_NGINX_USER_CONFIG_SKIPPED, \pmssCreateNginxConfigGenerateUser('alice', $ctx, false));
        $this->assertFalse(file_exists($users.'/alice'));
        $this->assertFalse(file_exists($public));
    }

    public function testIntentionalSkipKeepsSingleUserRouteButFullRunRemovesIt(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'homeBase' => $this->tempDir.'/home',
            'runtimePortDir' => $this->tempDir.'/ports', 'subdomainEnabled' => false];
        @mkdir($ctx['homeBase'].'/alice', 0755, true);
        $route = $this->pmssWriteFile($ctx['nginxUsersDir'].'/alice', "old route\n");

        $this->assertSame(PMSS_NGINX_USER_CONFIG_SKIPPED, \pmssCreateNginxConfigGenerateUser('alice', $ctx, true));
        $this->assertTrue(is_file($route));
        $this->assertSame(PMSS_NGINX_USER_CONFIG_SKIPPED, \pmssCreateNginxConfigGenerateUser('alice', $ctx, false));
        $this->assertFalse(file_exists($route));
    }

    public function testServiceableRouteRequiresSafeRegularFile(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainEnabled' => false];
        $route = $this->pmssWriteFile($ctx['nginxUsersDir'].'/alice', "route\n");
        $this->assertTrue(\pmssCreateNginxConfigUserRouteIsServiceable('alice', $ctx));
        @unlink($route);
        $target = $this->pmssWriteFile($this->tempDir.'/target', "route\n");
        if (!@symlink($target, $route)) throw new SkipTest('symlink unavailable');
        $this->assertFalse(\pmssCreateNginxConfigUserRouteIsServiceable('alice', $ctx));
        @unlink($route);
        $this->assertFalse(\pmssCreateNginxConfigUserRouteIsServiceable('alice', $ctx));
    }

    public function testSetupDoesNotDeleteRoutesBeforeGeneration(): void
    {
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/nginxConfig/setup.php', [
            "glob('/etc/nginx/users/*')",
            "glob(\$subdomainConfigDir.'/pmss-user-*.conf')",
            "@unlink(\$subdomainConfigDir.'/pmss-user-'.\$requestedUser.'.conf')",
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/nginxConfig/main.php', [
            'pmssCreateNginxConfigPruneOrphans($users, $ctx)',
            'PMSS_NGINX_USER_CONFIG_WRITE_FAILED',
            'pmssCreateNginxConfigUserRouteIsServiceable($thisUser, $ctx)',
        ]);
    }

    public function testExtractedGenerationHelpersKeepSubdomainAndLegacyDelugeContracts(): void
    {
        $configDir = $this->tempDir.'/conf.d';
        @mkdir($configDir, 0755, true);
        $ctx = ['subdomainConfigDir' => $configDir, 'nginxSslBlock' => 'ssl-on', 'publicSubdomainTemplate' => 'public ##host## ##user## ##port## ##ssl_block##'];
        $this->assertTrue(\pmssCreateNginxConfigWriteSubdomainConfigs($ctx, 'alice', 'example.test', false, 12345));
        $this->assertEquals('public alice.example.test alice 12345 ssl-on', (string) file_get_contents($configDir.'/pmss-user-alice.conf'));
        $this->assertFalse(file_exists($configDir.'/pmss-user-alice-hash.conf'));

        $homeDir = $this->tempDir.'/home/alice';
        @mkdir($homeDir, 0755, true);
        $target = $this->pmssWriteFile($this->tempDir.'/outside-port', "62000\n");
        if (!@symlink($target, $homeDir.'/.delugeWebPort')) throw new SkipTest('symlink unavailable');
        $this->pmssWriteFile($homeDir.'/.delugePort', "65535\n");
        $this->assertEquals(1, \pmssCreateNginxConfigLegacyDelugeWebPort($homeDir, 'alice'));
    }

    public function testGeneratorUsesGuardedWriterForNginxOutputs(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/nginxConfig/userConfigsGenerate.php', [
            "require_once __DIR__.'/userConfigsReconcile.php';",
            'function pmssCreateNginxConfigWriteFile(string $path, string $content, string $user, string $label): bool',
            'pmssWriteManagedFile($path, $content, \'root\', \'root\', 0640)',
            'pmssCreateNginxConfigReconcileStaleUserFiles($thisUser, $ctx, $writtenPaths);',
        ]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/nginxConfig/userConfigsReconcile.php', [
            "require_once __DIR__.'/../lighttpd/userFileWrite.php';",
            'function pmssCreateNginxConfigRemoveFile(string $path, string $user, string $label): bool',
        ]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/nginxConfig/userConfigsGenerate.php', [
            'file_put_contents($subdomainConfigDir',
            'file_put_contents("/etc/nginx/users/',
            '@unlink("/etc/nginx/users/',
        ]);
    }
}
