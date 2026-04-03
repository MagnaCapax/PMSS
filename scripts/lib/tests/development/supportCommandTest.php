<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/support/request.php';
require_once dirname(__DIR__, 2).'/update.php';
require_once dirname(__DIR__, 2).'/update/users.php';

class SupportCommandTest extends TestCase
{
    private $envBackup = [];
    private $homeRoot;
    private $configDir;
    private $skelDir;
    private $user;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->homeRoot = sys_get_temp_dir().'/pmss-support-home-'.$suffix;
        $this->configDir = sys_get_temp_dir().'/pmss-support-config-'.$suffix;
        $this->skelDir = sys_get_temp_dir().'/pmss-support-skel-'.$suffix;
        $this->user = 'user'.bin2hex(random_bytes(2));
        $this->envBackup = $this->pmssCaptureEnv(['HOME', 'USER', 'PMSS_CONFIG_DIR', 'PMSS_SUPPORT_CONFIG_PATH', 'PMSS_VERSION_FILE', 'PMSS_HOME_DIR', 'PMSS_SKEL_DIR']);

        $this->pmssEnsureDir($this->homeRoot.'/'.$this->user);
        $this->pmssEnsureDir($this->configDir);
        $this->pmssEnsureDir($this->skelDir.'/bin');
        file_put_contents($this->configDir.'/support.php', "<?php\nreturn ".var_export([
            'targetEmail' => 'support@example.com',
            'snapshotDirectory' => '.support/requests',
            'smtpPort' => 25,
            'connectTimeout' => 5,
            'relayHost' => 'mx.example.com',
        ], true).";\n");
        file_put_contents($this->configDir.'/version', "main@test\n");

        putenv('HOME='.$this->homeRoot.'/'.$this->user);
        putenv('USER='.$this->user);
        putenv('PMSS_CONFIG_DIR='.$this->configDir);
        putenv('PMSS_VERSION_FILE='.$this->configDir.'/version');
        putenv('PMSS_HOME_DIR='.$this->homeRoot);
        putenv('PMSS_SKEL_DIR='.$this->skelDir);
    }

    protected function tearDown(): void
    {
        $this->pmssRestoreEnvMap($this->envBackup);
        $this->cleanup($this->homeRoot);
        $this->cleanup($this->configDir);
        $this->cleanup($this->skelDir);
    }

    public function testMessageNormalizeRejectsEmptyInput(): void
    {
        $caught = false;
        try {
            \pmssSupportMessageNormalize(" \n ");
        } catch (\InvalidArgumentException $exception) {
            $caught = true;
        }

        $this->assertTrue($caught, 'empty messages must be rejected');
    }

    public function testBillingIdReadAcceptsPositiveInteger(): void
    {
        file_put_contents($this->homeRoot.'/'.$this->user.'/.billingId', "42\n");

        $this->assertEquals(42, \pmssSupportBillingIdRead($this->homeRoot.'/'.$this->user));
    }

    public function testBillingIdReadRejectsSymlink(): void
    {
        $target = $this->homeRoot.'/'.$this->user.'/billing-id-target';
        file_put_contents($target, "41\n");
        symlink($target, $this->homeRoot.'/'.$this->user.'/.billingId');

        $this->assertEquals(0, \pmssSupportBillingIdRead($this->homeRoot.'/'.$this->user));
    }

    public function testConfigReadHonorsExplicitConfigPathOverride(): void
    {
        $customPath = $this->configDir.'/support-custom.php';
        file_put_contents($customPath, "<?php\nreturn ".var_export([
            'targetEmail' => 'override@example.com',
            'snapshotDirectory' => '.support/requests',
            'smtpPort' => 26,
            'connectTimeout' => 6,
            'relayHost' => 'mx-override.example.com',
        ], true).";\n");
        putenv('PMSS_SUPPORT_CONFIG_PATH='.$customPath);

        $config = \pmssSupportConfigRead();

        $this->assertSame('override@example.com', $config['targetEmail']);
        $this->assertSame(26, $config['smtpPort']);
    }

    public function testConfigReadRejectsUnreadableOverridePath(): void
    {
        $customPath = $this->configDir.'/support-unreadable.php';
        file_put_contents($customPath, "<?php\nreturn ".var_export([
            'targetEmail' => 'override@example.com',
            'snapshotDirectory' => '.support/requests',
            'smtpPort' => 26,
            'connectTimeout' => 6,
            'relayHost' => 'mx-override.example.com',
        ], true).";\n");
        chmod($customPath, 0000);
        putenv('PMSS_SUPPORT_CONFIG_PATH='.$customPath);

        $caught = false;
        try {
            \pmssSupportConfigRead();
        } catch (\RuntimeException $exception) {
            $caught = true;
            $this->assertSame('Support command config is missing or unreadable.', $exception->getMessage());
        } finally {
            chmod($customPath, 0600);
        }

        $this->assertTrue($caught, 'unreadable support config overrides must be rejected');
    }

    public function testIdentityReadRejectsUnsafeEnvironmentUsername(): void
    {
        putenv('USER=../escape');
        putenv('HOME=/home/../escape');

        $identity = \pmssSupportIdentityRead();

        $this->assertTrue($identity['username'] !== '../escape');
        $this->assertTrue($identity['home'] !== '/home/../escape');
    }

    public function testDiagnosticsBuildUsesRunnerOutputs(): void
    {
        $outputs = [];
        $diagnostics = \pmssSupportDiagnosticsBuild('Need help', function (array $command) use (&$outputs): array {
            $outputs[] = $command;
            return ['rc' => 0, 'output' => implode(' ', $command)];
        });

        $this->assertEquals($this->user, $diagnostics['username']);
        $this->assertStringContainsString('Need help', $diagnostics['body']);
        $this->assertSame([
            ['uptime'],
            ['df', '-P', '-h', $this->homeRoot.'/'.$this->user],
            ['pgrep', '-a', '-u', $this->user, '-x', 'rtorrent'],
            ['pgrep', '-a', '-u', $this->user, '-x', 'deluged'],
            ['pgrep', '-a', '-u', $this->user, '-x', 'deluge-web'],
            ['pgrep', '-a', '-u', $this->user, '-x', 'qbittorrent-nox'],
        ], $outputs);
    }

    public function testSnapshotWriteCreatesPrivateFile(): void
    {
        $diagnostics = \pmssSupportDiagnosticsBuild('Please investigate', function (array $command): array {
            return ['rc' => 0, 'output' => implode(' ', $command)];
        });
        $config = \pmssSupportConfigRead();

        $path = \pmssSupportSnapshotWrite($diagnostics, $config);

        $this->assertTrue(is_file($path));
        $this->assertEquals(0600, fileperms($path) & 0777);
        $this->assertStringContainsString('Please investigate', (string) file_get_contents($path));
    }

    public function testSnapshotWriteTightensExistingDirectoryPermissions(): void
    {
        $snapshotDir = $this->homeRoot.'/'.$this->user.'/.support/requests';
        mkdir($snapshotDir, 0755, true);
        chmod($snapshotDir, 0755);

        $diagnostics = \pmssSupportDiagnosticsBuild('Please investigate', function (array $command): array {
            return ['rc' => 0, 'output' => implode(' ', $command)];
        });
        $config = \pmssSupportConfigRead();

        $path = \pmssSupportSnapshotWrite($diagnostics, $config);

        clearstatcache(true, $snapshotDir);
        $this->assertTrue(is_file($path));
        $this->assertEquals(0700, fileperms($snapshotDir) & 0777);
    }

    public function testSnapshotWriteUsesSharedFullWriteGuard(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/support/diagnostics.php');

        $this->assertStringContainsString(
            "pmssSupportStreamWriteAll(\$handle, (string) (\$diagnostics['body'] ?? ''), 'support snapshot file');",
            $source
        );
        $this->assertStringContainsString("@fflush(\$handle) !== true", $source);
        $this->assertStringContainsString("@chmod(\$snapshotDir, 0700);", $source);
        $this->assertStringContainsString("@chmod(\$path, 0600);", $source);
    }

    public function testSnapshotWriteRejectsSymlinkedSupportPathAncestor(): void
    {
        $target = $this->homeRoot.'/'.$this->user.'/support-target';
        mkdir($target, 0700, true);
        symlink($target, $this->homeRoot.'/'.$this->user.'/.support');

        $diagnostics = \pmssSupportDiagnosticsBuild('Please investigate', function (array $command): array {
            return ['rc' => 0, 'output' => implode(' ', $command)];
        });
        $config = \pmssSupportConfigRead();

        $caught = false;
        try {
            \pmssSupportSnapshotWrite($diagnostics, $config);
        } catch (\RuntimeException $exception) {
            $caught = true;
        }

        $this->assertTrue($caught, 'symlinked support snapshot parents must be rejected');
    }

    public function testRequestSubmitWritesSnapshotAndUsesTransport(): void
    {
        $deliveries = [];

        $result = \pmssSupportRequestSubmit(
            'rtorrent is stuck',
            function (array $command): array {
                return ['rc' => 0, 'output' => implode(' ', $command)];
            },
            function (array $config, array $envelope) use (&$deliveries): void {
                $deliveries[] = ['config' => $config, 'envelope' => $envelope];
            }
        );

        $this->assertTrue(is_file($result['snapshotPath']));
        $this->assertEquals('support@example.com', $deliveries[0]['config']['targetEmail']);
        $this->assertStringContainsString('Subject: [PMSS Support] billing=0', $deliveries[0]['envelope']['data']);
        $this->assertStringContainsString('Snapshot: '.$result['snapshotPath'], $deliveries[0]['envelope']['data']);
    }

    public function testApplySkeletonFilesCopiesSupportCommandForExistingUsers(): void
    {
        file_put_contents($this->skelDir.'/bin/support', "#!/usr/bin/env bash\necho support\n");

        \pmssUserApplySkeletonFiles($this->pmssUserHomeContext($this->homeRoot, $this->user));

        $this->assertTrue(is_file($this->homeRoot.'/'.$this->user.'/bin/support'));
    }

    public function testShippedSkeletonSupportWrapperTargetsUtility(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/bin/support');

        $this->assertStringContainsString('php /scripts/util/supportCommand.php', $source);
    }
}
