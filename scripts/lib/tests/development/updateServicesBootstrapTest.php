<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

class UpdateServicesBootstrapTest extends TestCase
{
    private function assertBootstrapSkipBehavior(
        string $skipEnv,
        string $skipValue,
        string $valueEnv,
        ?string $value,
        callable $action,
        string $skipMessage,
        bool $shouldSkip,
        string $fallbackMessage
    ): void {
        $messages = [];

        $this->pmssWithEnv([
            $skipEnv => $skipValue,
            $valueEnv => $value,
        ], function () use (&$messages, $action): void {
            $action($this->pmssMakeArrayLogger($messages));
        });

        $this->assertEquals(
            $shouldSkip,
            $this->pmssMessagesContain($messages, $skipMessage),
            'unexpected skip behaviour for '.$skipEnv.'='.$skipValue
        );
        if (!$shouldSkip) {
            $this->assertTrue(
                $this->pmssMessagesContain($messages, $fallbackMessage),
                'expected fallback behaviour for '.$skipEnv.'='.$skipValue
            );
        }
    }

    public function testAuthorizedKeysDirectiveWriterStoresBackupAndUpdatedConfig(): void
    {
        $tempDir = $this->pmssMakeTempDir('pmss-sshd-authkeys-');
        $sshdConfig = $tempDir.'/sshd_config';
        $backupPath = $tempDir.'/pmss.sshd_config';
        $original = "#AuthorizedKeysFile .ssh/authorized_keys\nPasswordAuthentication yes\n";
        $updated = \pmssSshdAuthorizedKeysDirectiveNormalize($original);

        $this->pmssWriteFile($sshdConfig, $original);

        $this->assertTrue(\pmssSshdConfigWriteUpdated($sshdConfig, $original, $updated, 'sshd config', true, $backupPath));
        $this->assertEquals($updated, $this->pmssReadFileOrEmpty($sshdConfig));
        $this->assertEquals($original, $this->pmssReadFileOrEmpty($backupPath));
    }

    public function testAuthorizedKeysDirectiveNormalizeReturnsOriginalWhenAlreadyEnabled(): void
    {
        $config = "AuthorizedKeysFile .ssh/authorized_keys\nPasswordAuthentication yes\n";

        $this->assertSame($config, \pmssSshdAuthorizedKeysDirectiveNormalize($config));
    }

    public function testHostnameSkipTruthyValueSkips(): void
    {
        $this->assertBootstrapSkipBehavior(
            'PMSS_SKIP_HOSTNAME',
            'yes',
            'PMSS_HOSTNAME',
            null,
            '\pmssApplyHostnameConfig',
            'Hostname configuration skipped via PMSS_SKIP_HOSTNAME',
            true,
            'No hostname override provided'
        );
    }

    public function testHostnameSkipOnValueSkips(): void
    {
        $this->assertBootstrapSkipBehavior(
            'PMSS_SKIP_HOSTNAME',
            'on',
            'PMSS_HOSTNAME',
            null,
            '\pmssApplyHostnameConfig',
            'Hostname configuration skipped via PMSS_SKIP_HOSTNAME',
            true,
            'No hostname override provided'
        );
    }

    public function testHostnameSkipFalseyValueFallsThroughToMissingHostname(): void
    {
        $this->assertBootstrapSkipBehavior(
            'PMSS_SKIP_HOSTNAME',
            'no',
            'PMSS_HOSTNAME',
            null,
            '\pmssApplyHostnameConfig',
            'Hostname configuration skipped via PMSS_SKIP_HOSTNAME',
            false,
            'No hostname override provided'
        );
    }

    public function testHostnameSkipUppercaseFalseyValueFallsThroughToMissingHostname(): void
    {
        $this->assertBootstrapSkipBehavior(
            'PMSS_SKIP_HOSTNAME',
            'FALSE',
            'PMSS_HOSTNAME',
            null,
            '\pmssApplyHostnameConfig',
            'Hostname configuration skipped via PMSS_SKIP_HOSTNAME',
            false,
            'No hostname override provided'
        );
    }

    public function testQuotaSkipTruthyValueSkips(): void
    {
        $this->assertBootstrapSkipBehavior(
            'PMSS_SKIP_QUOTA',
            'on',
            'PMSS_QUOTA_MOUNT',
            null,
            '\pmssConfigureQuotaMount',
            'Quota configuration skipped via PMSS_SKIP_QUOTA',
            true,
            'Skipping remount for '
        );
    }

    public function testQuotaSkipFalseyValueFallsThroughPastSkipGuard(): void
    {
        $mount = sys_get_temp_dir().'/pmss-bootstrap-quota-'.bin2hex(random_bytes(4));
        $this->assertBootstrapSkipBehavior(
            'PMSS_SKIP_QUOTA',
            'no',
            'PMSS_QUOTA_MOUNT',
            $mount,
            '\pmssConfigureQuotaMount',
            'Quota configuration skipped via PMSS_SKIP_QUOTA',
            false,
            'Skipping remount for '.$mount.' (mount path not found)'
        );
    }

    public function testQuotaSkipUppercaseFalseyValueFallsThroughPastSkipGuard(): void
    {
        $mount = sys_get_temp_dir().'/pmss-bootstrap-quota-'.bin2hex(random_bytes(4));
        $this->assertBootstrapSkipBehavior(
            'PMSS_SKIP_QUOTA',
            'FALSE',
            'PMSS_QUOTA_MOUNT',
            $mount,
            '\pmssConfigureQuotaMount',
            'Quota configuration skipped via PMSS_SKIP_QUOTA',
            false,
            'Skipping remount for '.$mount.' (mount path not found)'
        );
    }
}
