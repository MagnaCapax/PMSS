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
        $path = $this->tempDir.'/conf.d/pmss-user-fixtur01.conf';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssCreateNginxConfigWriteFile($path, "server {}\n", 'fixtur01', 'public subdomain config'));
        $this->assertEquals("server {}\n", (string) file_get_contents($path));
        $this->assertEquals(0640, fileperms($path) & 0777);
    }

    public function testWriteFileReplacesExistingRegularFile(): void
    {
        $path = $this->tempDir.'/users/fixtur01';
        $this->pmssWriteFile($path, "old\n");

        $this->assertTrue(\pmssCreateNginxConfigWriteFile($path, "new\n", 'fixtur01', 'user config'));
        $this->assertEquals("new\n", (string) file_get_contents($path));
    }

    public function testWriteFileRejectsSymlinkTarget(): void
    {
        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real.conf', $this->tempDir.'/link.conf', "keep\n");

        $this->assertFalse(\pmssCreateNginxConfigWriteFile($linkPath, "replace\n", 'fixtur01', 'user config'));
        $this->assertEquals("keep\n", (string) file_get_contents($realPath));
    }

    public function testWriteFileRejectsMissingParentDirectory(): void
    {
        $path = $this->tempDir.'/missing/pmss-user-fixtur01.conf';

        $this->assertFalse(\pmssCreateNginxConfigWriteFile($path, "server {}\n", 'fixtur01', 'public subdomain config'));
        $this->assertFalse(file_exists($path));
    }

    public function testRemoveFileDeletesRegularFile(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/users/fixtur01', "old\n");

        $this->assertTrue(\pmssCreateNginxConfigRemoveFile($path, 'fixtur01', 'stale user config'));
        $this->assertFalse(file_exists($path));
    }

    public function testRemoveFileTreatsMissingFileAsConverged(): void
    {
        $path = $this->tempDir.'/users/missing';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssCreateNginxConfigRemoveFile($path, 'fixtur01', 'stale user config'));
        $this->assertFalse(file_exists($path));
    }

    public function testRemoveFileRejectsSymlinkTarget(): void
    {
        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real.conf', $this->tempDir.'/link.conf', "keep\n");

        $this->assertFalse(\pmssCreateNginxConfigRemoveFile($linkPath, 'fixtur01', 'stale user config'));
        $this->assertEquals("keep\n", (string) file_get_contents($realPath));
        $this->assertTrue(is_link($linkPath), 'symlink target must remain untouched');
    }

    public function testRemoveFileRejectsDirectoryTarget(): void
    {
        $path = $this->tempDir.'/users/fixtur01';
        @mkdir($path, 0755, true);

        $this->assertFalse(\pmssCreateNginxConfigRemoveFile($path, 'fixtur01', 'stale user config'));
        $this->assertTrue(is_dir($path), 'directory target must remain untouched');
    }

    public function testReconcileRemovesOnlyObsoleteUserVariants(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainConfigDir' => $this->tempDir.'/conf.d', 'subdomainEnabled' => true];
        $paths = \pmssCreateNginxConfigManagedUserPaths('fixtur01', $ctx);
        foreach ($paths as $path) $this->pmssWriteFile($path, "old\n");

        $this->assertTrue(\pmssCreateNginxConfigReconcileStaleUserFiles('fixtur01', $ctx, [$paths['user'], $paths['public']]));
        $this->assertTrue(is_file($paths['user']));
        $this->assertTrue(is_file($paths['public']));
        $this->assertFalse(file_exists($paths['private']));
    }

    public function testOrphanPruneUsesSelectedUserSet(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainConfigDir' => $this->tempDir.'/conf.d', 'subdomainEnabled' => true];
        foreach (['fixtur01', 'fixtur02'] as $user) {
            foreach (\pmssCreateNginxConfigManagedUserPaths($user, $ctx) as $path) $this->pmssWriteFile($path, "managed\n");
        }

        $this->assertTrue(\pmssCreateNginxConfigPruneOrphans(['fixtur01'], $ctx));
        foreach (\pmssCreateNginxConfigManagedUserPaths('fixtur01', $ctx) as $label => $path) {
            $this->assertSame($label !== 'private', is_file($path));
        }
        foreach (\pmssCreateNginxConfigManagedUserPaths('fixtur02', $ctx) as $path) $this->assertFalse(file_exists($path));
    }

    public function testOrphanPruneRemovesARemovedAccountsPublishedBillingIdentity(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainConfigDir' => $this->tempDir.'/conf.d', 'subdomainEnabled' => true,
            'billingPublicDir' => $this->tempDir.'/billing'];
        $current = $this->pmssWriteFile($ctx['billingPublicDir'].'/fixtur01', "123\n");
        $removed = $this->pmssWriteFile($ctx['billingPublicDir'].'/fixtur02', "456\n");
        $kept = [$this->pmssWriteFile($ctx['billingPublicDir'].'/README', "keep\n"), $this->pmssWriteFile($ctx['billingPublicDir'].'/FIXTUR01', "keep\n")];

        $this->assertTrue(\pmssCreateNginxConfigPruneOrphans(['fixtur01'], $ctx));
        $this->assertSame("123\n", file_get_contents($current));
        $this->assertFalse(file_exists($removed));
        foreach ($kept as $path) $this->assertSame("keep\n", file_get_contents($path));

        unset($ctx['billingPublicDir']);
        $this->assertTrue(\pmssCreateNginxConfigPruneOrphans([], $ctx));
        $this->assertSame("123\n", file_get_contents($current));
    }

    public function testOrphanPruneKeepsFilesOutsideGeneratedNameShapes(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainConfigDir' => $this->tempDir.'/conf.d', 'subdomainEnabled' => true];
        $kept = [
            $this->pmssWriteFile($ctx['nginxUsersDir'].'/README', "keep\n"),
            $this->pmssWriteFile($ctx['nginxUsersDir'].'/fixtur01.backup', "keep\n"),
            $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-FIXTUR01.conf', "keep\n"),
            $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-fixtur01-extra.conf', "keep\n"),
        ];
        $orphan = $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-fixtur02.conf', "remove\n");

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
        $generated = $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-fixtur01-hash.conf', "old\n");
        $lookalike = $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-FIXTUR01-hash.conf', "keep\n");
        $backup = $this->pmssWriteFile($ctx['subdomainConfigDir'].'/pmss-user-fixtur01-hash.conf.bak', "keep\n");

        $this->assertTrue(\pmssCreateNginxConfigPruneOrphans(['fixtur01'], $ctx));
        $this->assertFalse(file_exists($generated));
        $this->assertTrue(is_file($lookalike));
        $this->assertTrue(is_file($backup));
    }

    public function testGeneratorWriteFailurePreservesPriorTarget(): void
    {
        $homeBase = $this->tempDir.'/home';
        $runtimePortDir = $this->tempDir.'/ports';
        $nginxUsersDir = $this->tempDir.'/users';
        $this->pmssWriteFile($homeBase.'/fixtur01/.rtorrent.rc', "schedule = test\n");
        $this->pmssWriteFile($homeBase.'/fixtur01/.lighttpd.conf', "server.port = 12345\n");
        $this->pmssWriteFile($runtimePortDir.'/lighttpd-fixtur01', "12345\n");
        $priorRoute = $this->pmssWriteFile($this->tempDir.'/prior-route', "old route\n");
        @mkdir($nginxUsersDir, 0755, true);
        if (!@symlink($priorRoute, $nginxUsersDir.'/fixtur01')) throw new SkipTest('symlink unavailable');
        $ctx = ['nginxUsersDir' => $nginxUsersDir, 'homeBase' => $homeBase, 'runtimePortDir' => $runtimePortDir,
            'userTemplate' => 'proxy ##username ##serverPort', 'subdomainEnabled' => false];

        $this->assertSame(PMSS_NGINX_USER_CONFIG_WRITE_FAILED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, false));
        $this->assertEquals("old route\n", (string) file_get_contents($priorRoute));
        $this->assertTrue(is_link($nginxUsersDir.'/fixtur01'));
    }

    public function testActiveAndSuspendedGenerationKeepsRenderedRoutesAndCleanup(): void
    {
        $home = $this->tempDir.'/home/fixtur01';
        $users = $this->tempDir.'/users';
        $conf = $this->tempDir.'/conf.d';
        $ports = $this->tempDir.'/ports';
        $this->pmssWriteFile($home.'/.rtorrent.rc', "schedule = test\n");
        $this->pmssWriteFile($home.'/.lighttpd.conf', "server.port = 12345\n");
        $this->pmssWriteFile($ports.'/lighttpd-fixtur01', "12345\n");
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
        $public = $conf.'/pmss-user-fixtur01.conf';
        $private = $conf.'/pmss-user-fixtur01-hash.conf';
        $this->pmssWriteFile($private, "stale\n");

        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, false));
        $this->assertSame('active fixtur01 12345', file_get_contents($users.'/fixtur01'));
        $this->assertSame('public fixtur01.example.test 12345', file_get_contents($public));
        $this->assertFalse(file_exists($private));

        $ctx['userTemplate'] = false;
        $this->assertSame(PMSS_NGINX_USER_CONFIG_SKIPPED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, false));
        $this->assertFalse(file_exists($users.'/fixtur01'));
        $this->assertSame('public fixtur01.example.test 12345', file_get_contents($public));

        @mkdir($home.'/www-disabled', 0755);
        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, false));
        $this->assertSame('suspended fixtur01', file_get_contents($users.'/fixtur01'));
        $this->assertSame('suspended public fixtur01.example.test', file_get_contents($public));
        $this->assertFalse(file_exists($private));

        $ctx['suspendedTemplate'] = false;
        $this->assertSame(PMSS_NGINX_USER_CONFIG_SKIPPED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, false));
        $this->assertFalse(file_exists($users.'/fixtur01'));
        $this->assertFalse(file_exists($public));
    }

    public function testPrimaryTemplateRenderingPreservesRepeatedAndUnknownTokens(): void
    {
        $home = $this->tempDir.'/home/fixtur01';
        $this->pmssWriteFile($home.'/.rtorrent.rc', "schedule = test\n");
        $this->pmssWriteFile($home.'/.lighttpd.conf', "server.port = 12345\n");
        $this->pmssWriteFile($this->tempDir.'/ports/lighttpd-fixtur01', "12345\n");
        @mkdir($this->tempDir.'/users', 0755, true);
        $ctx = [
            'homeBase' => $this->tempDir.'/home', 'runtimePortDir' => $this->tempDir.'/ports',
            'nginxUsersDir' => $this->tempDir.'/users', 'subdomainEnabled' => false,
            'userTemplate' => '##username|##username|##serverPort|##unknown',
            'suspendedTemplate' => '##username|##serverPort|##unknown',
        ];

        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, false));
        $this->assertSame('fixtur01|fixtur01|12345|##unknown', file_get_contents($this->tempDir.'/users/fixtur01'));

        $ctx['userTemplate'] = '##delugeWebPort|##delugeWebPort|##serverPort';
        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, false));
        $this->assertSame('1|1|12345', file_get_contents($this->tempDir.'/users/fixtur01'));

        @mkdir($home.'/www-disabled', 0755);
        $this->assertSame(PMSS_NGINX_USER_CONFIG_GENERATED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, false));
        $this->assertSame('fixtur01|##serverPort|##unknown', file_get_contents($this->tempDir.'/users/fixtur01'));
    }

    public function testIntentionalSkipKeepsSingleUserRouteButFullRunRemovesIt(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'homeBase' => $this->tempDir.'/home',
            'runtimePortDir' => $this->tempDir.'/ports', 'subdomainEnabled' => false];
        @mkdir($ctx['homeBase'].'/fixtur01', 0755, true);
        $route = $this->pmssWriteFile($ctx['nginxUsersDir'].'/fixtur01', "old route\n");

        $this->assertSame(PMSS_NGINX_USER_CONFIG_SKIPPED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, true));
        $this->assertTrue(is_file($route));
        $this->assertSame(PMSS_NGINX_USER_CONFIG_SKIPPED, \pmssCreateNginxConfigGenerateUser('fixtur01', $ctx, false));
        $this->assertFalse(file_exists($route));
    }

    public function testServiceableRouteRequiresSafeRegularFile(): void
    {
        $ctx = ['nginxUsersDir' => $this->tempDir.'/users', 'subdomainEnabled' => false];
        $route = $this->pmssWriteFile($ctx['nginxUsersDir'].'/fixtur01', "route\n");
        $this->assertTrue(\pmssCreateNginxConfigUserRouteIsServiceable('fixtur01', $ctx));
        @unlink($route);
        $target = $this->pmssWriteFile($this->tempDir.'/target', "route\n");
        if (!@symlink($target, $route)) throw new SkipTest('symlink unavailable');
        $this->assertFalse(\pmssCreateNginxConfigUserRouteIsServiceable('fixtur01', $ctx));
        @unlink($route);
        $this->assertFalse(\pmssCreateNginxConfigUserRouteIsServiceable('fixtur01', $ctx));
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
        $this->assertTrue(\pmssCreateNginxConfigWriteSubdomainConfigs($ctx, 'fixtur01', 'example.test', false, 12345));
        $this->assertEquals('public fixtur01.example.test fixtur01 12345 ssl-on', (string) file_get_contents($configDir.'/pmss-user-fixtur01.conf'));
        $this->assertFalse(file_exists($configDir.'/pmss-user-fixtur01-hash.conf'));

        $homeDir = $this->tempDir.'/home/fixtur01';
        @mkdir($homeDir, 0755, true);
        $target = $this->pmssWriteFile($this->tempDir.'/outside-port', "62000\n");
        if (!@symlink($target, $homeDir.'/.delugeWebPort')) throw new SkipTest('symlink unavailable');
        $this->pmssWriteFile($homeDir.'/.delugePort', "65535\n");
        $this->assertEquals(1, \pmssCreateNginxConfigLegacyDelugeWebPort($homeDir, 'fixtur01'));
    }

    public function testLegacyDelugePortReaderRequiresOpenedRegularEntry(): void
    {
        $homeDir = $this->tempDir.'/home/fixtur01';
        @mkdir($homeDir, 0755, true);
        $port = $this->pmssWriteFile($homeDir.'/.delugeWebPort', "23000\n");
        $owner = (int) fileowner($port);
        $this->assertSame("23000\n", \pmssReadRegularFileContentsVerified($port, $owner, 32));
        $this->assertSame(null, \pmssReadRegularFileContentsVerified($port, $owner + 1, 32));
        $this->assertSame(null, \pmssReadRegularFileContentsVerified($port, $owner, 3));
        $second = $homeDir.'/.delugePort';
        $this->assertTrue(link($port, $second));
        $this->assertSame(null, \pmssReadRegularFileContentsVerified($port, $owner, 32));
        $this->assertSame(1, \pmssCreateNginxConfigLegacyDelugeWebPort($homeDir, 'fixtur01'));
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
