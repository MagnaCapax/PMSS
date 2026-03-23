<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

class UpdateServicesBootstrapTest extends TestCase
{
    use FilesystemCleanupTrait;

    public function testHostnameSkipTruthyValueSkips(): void
    {
        $messages = [];

        $this->pmssWithEnv([
            'PMSS_SKIP_HOSTNAME' => 'yes',
            'PMSS_HOSTNAME' => null,
        ], function () use (&$messages): void {
            \pmssApplyHostnameConfig($this->pmssMakeArrayLogger($messages));
        });

        $this->assertTrue(
            $this->pmssMessagesContain($messages, 'Hostname configuration skipped via PMSS_SKIP_HOSTNAME'),
            'expected hostname skip log when PMSS_SKIP_HOSTNAME is truthy'
        );
    }

    public function testHostnameSkipOnValueSkips(): void
    {
        $messages = [];

        $this->pmssWithEnv([
            'PMSS_SKIP_HOSTNAME' => 'on',
            'PMSS_HOSTNAME' => null,
        ], function () use (&$messages): void {
            \pmssApplyHostnameConfig($this->pmssMakeArrayLogger($messages));
        });

        $this->assertTrue(
            $this->pmssMessagesContain($messages, 'Hostname configuration skipped via PMSS_SKIP_HOSTNAME'),
            'expected hostname skip log when PMSS_SKIP_HOSTNAME is set to on'
        );
    }

    public function testHostnameSkipFalseyValueFallsThroughToMissingHostname(): void
    {
        $messages = [];

        $this->pmssWithEnv([
            'PMSS_SKIP_HOSTNAME' => 'no',
            'PMSS_HOSTNAME' => null,
        ], function () use (&$messages): void {
            \pmssApplyHostnameConfig($this->pmssMakeArrayLogger($messages));
        });

        $this->assertTrue(
            !$this->pmssMessagesContain($messages, 'Hostname configuration skipped via PMSS_SKIP_HOSTNAME'),
            'falsey PMSS_SKIP_HOSTNAME must not trigger skip'
        );
        $this->assertTrue(
            $this->pmssMessagesContain($messages, 'No hostname override provided'),
            'falsey PMSS_SKIP_HOSTNAME should fall through to missing-hostname handling'
        );
    }

    public function testHostnameSkipUppercaseFalseyValueFallsThroughToMissingHostname(): void
    {
        $messages = [];

        $this->pmssWithEnv([
            'PMSS_SKIP_HOSTNAME' => 'FALSE',
            'PMSS_HOSTNAME' => null,
        ], function () use (&$messages): void {
            \pmssApplyHostnameConfig($this->pmssMakeArrayLogger($messages));
        });

        $this->assertTrue(
            !$this->pmssMessagesContain($messages, 'Hostname configuration skipped via PMSS_SKIP_HOSTNAME'),
            'uppercase falsey PMSS_SKIP_HOSTNAME must not trigger skip'
        );
        $this->assertTrue(
            $this->pmssMessagesContain($messages, 'No hostname override provided'),
            'uppercase falsey PMSS_SKIP_HOSTNAME should fall through to missing-hostname handling'
        );
    }

    public function testQuotaSkipTruthyValueSkips(): void
    {
        $messages = [];

        $this->pmssWithEnv([
            'PMSS_SKIP_QUOTA' => 'on',
            'PMSS_QUOTA_MOUNT' => null,
        ], function () use (&$messages): void {
            \pmssConfigureQuotaMount($this->pmssMakeArrayLogger($messages));
        });

        $this->assertTrue(
            $this->pmssMessagesContain($messages, 'Quota configuration skipped via PMSS_SKIP_QUOTA'),
            'expected quota skip log when PMSS_SKIP_QUOTA is truthy'
        );
    }

    public function testQuotaSkipFalseyValueFallsThroughPastSkipGuard(): void
    {
        $messages = [];
        $mount = sys_get_temp_dir().'/pmss-bootstrap-quota-'.bin2hex(random_bytes(4));

        $this->pmssWithEnv([
            'PMSS_SKIP_QUOTA' => 'no',
            'PMSS_QUOTA_MOUNT' => $mount,
        ], function () use (&$messages): void {
            \pmssConfigureQuotaMount($this->pmssMakeArrayLogger($messages));
        });

        $this->assertTrue(
            !$this->pmssMessagesContain($messages, 'Quota configuration skipped via PMSS_SKIP_QUOTA'),
            'falsey PMSS_SKIP_QUOTA must not trigger skip'
        );
        $this->assertTrue(
            $this->pmssMessagesContain($messages, 'Skipping remount for '.$mount.' (mount path not found)'),
            'falsey PMSS_SKIP_QUOTA should fall through to normal quota handling'
        );
    }

    public function testQuotaSkipUppercaseFalseyValueFallsThroughPastSkipGuard(): void
    {
        $messages = [];
        $mount = sys_get_temp_dir().'/pmss-bootstrap-quota-'.bin2hex(random_bytes(4));

        $this->pmssWithEnv([
            'PMSS_SKIP_QUOTA' => 'FALSE',
            'PMSS_QUOTA_MOUNT' => $mount,
        ], function () use (&$messages): void {
            \pmssConfigureQuotaMount($this->pmssMakeArrayLogger($messages));
        });

        $this->assertTrue(
            !$this->pmssMessagesContain($messages, 'Quota configuration skipped via PMSS_SKIP_QUOTA'),
            'uppercase falsey PMSS_SKIP_QUOTA must not trigger skip'
        );
        $this->assertTrue(
            $this->pmssMessagesContain($messages, 'Skipping remount for '.$mount.' (mount path not found)'),
            'uppercase falsey PMSS_SKIP_QUOTA should fall through to normal quota handling'
        );
    }

}
