<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/support/request.php';
require_once dirname(__DIR__, 2).'/update.php';
require_once dirname(__DIR__, 2).'/update/users.php';

class SupportCommandTest extends TestCase
{
    private $homeRoot;
    private $configDir;
    private $skelDir;
    private $user;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('homeRoot', 'pmss-support-home-');
        $this->pmssAssignTempDirProperty('configDir', 'pmss-support-config-');
        $this->pmssAssignTempDirProperty('skelDir', 'pmss-support-skel-');
        $this->user = 'user'.bin2hex(random_bytes(2));

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

        $this->pmssTrackEnvKeys(['PMSS_SUPPORT_CONFIG_PATH']);
        $this->pmssTrackEnvOverrides([
            'HOME' => $this->homeRoot.'/'.$this->user,
            'USER' => $this->user,
            'PMSS_CONFIG_DIR' => $this->configDir,
            'PMSS_VERSION_FILE' => $this->configDir.'/version',
            'PMSS_HOME_DIR' => $this->homeRoot,
            'PMSS_SKEL_DIR' => $this->skelDir,
        ]);
    }

    public function testMessageNormalizeRejectsEmptyInput(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, static function (): void {
            \pmssSupportMessageNormalize(" \n ");
        });
    }

    public function testBillingServiceIdReadAcceptsPositiveInteger(): void
    {
        file_put_contents($this->homeRoot.'/'.$this->user.'/.billingServiceId', "42\n");

        $this->assertEquals(42, \pmssUserBillingServiceIdRead($this->homeRoot.'/'.$this->user));
    }

    public function testBillingServiceIdReadFallsBackToLegacyName(): void
    {
        file_put_contents($this->homeRoot.'/'.$this->user.'/.billingId', "43\n");

        $this->assertEquals(43, \pmssUserBillingServiceIdRead($this->homeRoot.'/'.$this->user));
    }

    public function testBillingClientIdReadAcceptsPositiveInteger(): void
    {
        file_put_contents($this->homeRoot.'/'.$this->user.'/.billingClientId', "99\n");

        $this->assertEquals(99, \pmssUserBillingClientIdRead($this->homeRoot.'/'.$this->user));
    }

    public function testBillingServiceIdReadRejectsSymlink(): void
    {
        $target = $this->homeRoot.'/'.$this->user.'/billing-id-target';
        file_put_contents($target, "41\n");
        symlink($target, $this->homeRoot.'/'.$this->user.'/.billingServiceId');

        $this->assertEquals(0, \pmssUserBillingServiceIdRead($this->homeRoot.'/'.$this->user));
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

        try {
            $this->assertThrowsRuntime(static function (): void {
                \pmssSupportConfigRead();
            }, 'Support command config is missing or unreadable.');
        } finally {
            chmod($customPath, 0600);
        }
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
        $diagnostics = \pmssSupportDiagnosticsBuild('Need help', $this->pmssCommandEchoRunner($outputs));

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

    public function testDiagnosticsBuildCarriesBillingIdentifiers(): void
    {
        file_put_contents($this->homeRoot.'/'.$this->user.'/.billingServiceId', "42\n");
        file_put_contents($this->homeRoot.'/'.$this->user.'/.billingClientId', "99\n");

        $diagnostics = \pmssSupportDiagnosticsBuild('Need help', $this->pmssCommandEchoRunner());

        $this->assertSame(42, $diagnostics['billingServiceId']);
        $this->assertSame(99, $diagnostics['billingClientId']);
        $this->assertStringContainsString('billing_service_id=42', $diagnostics['body']);
        $this->assertStringContainsString('billing_client_id=99', $diagnostics['body']);
    }

    public function testSnapshotWriteCreatesPrivateFile(): void
    {
        $diagnostics = \pmssSupportDiagnosticsBuild('Please investigate', $this->pmssCommandEchoRunner());
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

        $diagnostics = \pmssSupportDiagnosticsBuild('Please investigate', $this->pmssCommandEchoRunner());
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

        $diagnostics = \pmssSupportDiagnosticsBuild('Please investigate', $this->pmssCommandEchoRunner());
        $config = \pmssSupportConfigRead();

        $this->assertThrowsRuntime(static function () use ($diagnostics, $config): void {
            \pmssSupportSnapshotWrite($diagnostics, $config);
        });
    }

    public function testRequestSubmitWritesSnapshotAndUsesTransport(): void
    {
        $deliveries = [];

        $result = \pmssSupportRequestSubmit(
            'rtorrent is stuck',
            $this->pmssCommandEchoRunner(),
            function (array $config, array $envelope) use (&$deliveries): void {
                $deliveries[] = ['config' => $config, 'envelope' => $envelope];
            }
        );

        $this->assertTrue(is_file($result['snapshotPath']));
        $this->assertEquals('support@example.com', $deliveries[0]['config']['targetEmail']);
        $this->assertStringContainsString('Subject: [PMSS Support] billing_service=0', $deliveries[0]['envelope']['data']);
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
