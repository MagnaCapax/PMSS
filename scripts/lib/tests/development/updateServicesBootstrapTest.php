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
        $mount = $this->pmssMakeTempPath('pmss-bootstrap-quota-');
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
        $mount = $this->pmssMakeTempPath('pmss-bootstrap-quota-');
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

    public function testJournaledQuotaRemountDependsOnLiveQuotaState(): void
    {
        $mount = '/home/test mount';
        $options = 'noatime,usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1';
        foreach ([0 => false, 1 => true, 2 => true, 127 => false] as $rc => $expected) {
            $commands = [];
            $runner = static function (string $command) use ($rc, &$commands): array {
                $commands[] = $command;
                return ['rc' => $rc, 'stdout' => '', 'stderr' => ''];
            };
            $this->assertSame($expected, \pmssJournaledQuotaRemountBlocked($mount, $options, $runner));
            $this->assertSame(["quotaon -p '/home/test mount'"], $commands);
        }

        $called = false;
        $this->assertFalse(\pmssJournaledQuotaRemountBlocked($mount, 'noatime', static function () use (&$called): array {
            $called = true;
            return ['rc' => 2, 'stdout' => '', 'stderr' => ''];
        }));
        $this->assertFalse($called);
    }

    /** Exercise scripts/lib/quota/mount.php's caller with in-memory fstab and command capture. */
    public function testQuotaBootstrapRemountsFreshFstabOptionsOnlyWhenQuotaIsOff(): void
    {
        $journaled = 'usrjquota=aquota.user,grpjquota=aquota.group,jqfmt=vfsv1';
        $stubs = <<<'PHP'
namespace PMSS\QuotaBootstrapFixture;
function is_dir($path) { return true; }
function pmssEnsureQuotaOptions($mount, $options, $logger) {
    $GLOBALS['trace'][] = ['quota-options', $mount];
    \pmssFstabMountOptionsEnsure($GLOBALS['fstab'], $mount,
        ['usrjquota=aquota.user', 'grpjquota=aquota.group', 'jqfmt=vfsv1']);
}
function pmssEnsureJournalCommitOption($mount, $seconds, $logger) {}
function pmssEnsureMountNofailOption($mount, $logger) {}
function pmssFstabLinesRead($path, $logger, $context) {
    $GLOBALS['trace'][] = ['fstab', $path];
    return $GLOBALS['fstab'];
}
function pmssCommandCapture($command) {
    $GLOBALS['trace'][] = ['probe', $command];
    return ['rc' => $GLOBALS['rc'], 'stdout' => '', 'stderr' => ''];
}
function pmssQuotaCommandRun($command) {
    return \pmssQuotaCommandRun($command, __NAMESPACE__.'\\pmssCommandCapture');
}
function runStep($description, $command) { $GLOBALS['trace'][] = ['remount', $command]; }
function pmssWarnPendingJournalCommit($mount, $options, $logger) {
    $GLOBALS['trace'][] = ['pending', $mount];
}
function pmssWarnUnexpectedQuotaFiles($mount, $logger) { $GLOBALS['trace'][] = ['files', $mount]; }
PHP;
        $script = 'require '.var_export($this->pmssRepoPath('scripts/lib/quota/mount.php'), true).';'
            .'eval('.var_export($stubs, true).');';
        foreach (['pmssConfigureQuotaMount', 'pmssJournaledQuotaRemountBlocked'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'PMSS\QuotaBootstrapFixture');
        }

        foreach ([
            ['/home', 'defaults', 0, true],
            ['/home', $journaled, 0, true],
            ['/home', $journaled, 1, false],
            ['/home', $journaled, 2, false],
            ['/srv/fixture-data', 'defaults', 0, true],
        ] as [$mount, $options, $rc, $remount]) {
            // The real parser sees the options just added above, as it does on a fresh install.
            $fixture = '$GLOBALS["fstab"] = '.var_export(["fixture {$mount} ext4 {$options} 0 0"], true).';'
                .'$GLOBALS["rc"] = '.$rc.'; $GLOBALS["trace"] = [];'
                .'\PMSS\QuotaBootstrapFixture\pmssConfigureQuotaMount(static function ($message) {});'
                .'echo json_encode($GLOBALS["trace"]);';
            $run = $this->pmssExecInlinePhpWithTempStderr($script.$fixture, [
                'PMSS_SKIP_QUOTA' => '0', 'PMSS_QUOTA_MOUNT' => $mount,
            ]);
            $this->assertSame(0, $run['result']['rc'], (string) @file_get_contents($run['stderrPath']));
            $this->assertSame([
                ['quota-options', $mount],
                ['fstab', '/etc/fstab'],
                ['probe', 'quotaon -p '.escapeshellarg($mount)],
                $remount ? ['remount', 'mount -o remount '.escapeshellarg($mount)] : ['pending', $mount],
                ['files', $mount],
            ], $this->pmssDecodeJsonArray($run['result']['output']), $mount.' rc='.$rc.' options='.$options);
        }
    }
}
