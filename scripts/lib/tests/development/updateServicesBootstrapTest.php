<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

class UpdateServicesBootstrapTest extends TestCase
{
    public function testHostnameSkipTruthyValueSkips(): void
    {
        $messages = [];

        $this->withEnv([
            'PMSS_SKIP_HOSTNAME' => 'yes',
            'PMSS_HOSTNAME' => null,
        ], function () use (&$messages): void {
            \pmssApplyHostnameConfig(function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
        });

        $this->assertTrue(
            $this->messagesContain($messages, 'Hostname configuration skipped via PMSS_SKIP_HOSTNAME'),
            'expected hostname skip log when PMSS_SKIP_HOSTNAME is truthy'
        );
    }

    public function testHostnameSkipFalseyValueFallsThroughToMissingHostname(): void
    {
        $messages = [];

        $this->withEnv([
            'PMSS_SKIP_HOSTNAME' => 'no',
            'PMSS_HOSTNAME' => null,
        ], function () use (&$messages): void {
            \pmssApplyHostnameConfig(function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
        });

        $this->assertTrue(
            !$this->messagesContain($messages, 'Hostname configuration skipped via PMSS_SKIP_HOSTNAME'),
            'falsey PMSS_SKIP_HOSTNAME must not trigger skip'
        );
        $this->assertTrue(
            $this->messagesContain($messages, 'No hostname override provided'),
            'falsey PMSS_SKIP_HOSTNAME should fall through to missing-hostname handling'
        );
    }

    public function testQuotaSkipTruthyValueSkips(): void
    {
        $messages = [];

        $this->withEnv([
            'PMSS_SKIP_QUOTA' => 'on',
            'PMSS_QUOTA_MOUNT' => null,
        ], function () use (&$messages): void {
            \pmssConfigureQuotaMount(function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
        });

        $this->assertTrue(
            $this->messagesContain($messages, 'Quota configuration skipped via PMSS_SKIP_QUOTA'),
            'expected quota skip log when PMSS_SKIP_QUOTA is truthy'
        );
    }

    public function testQuotaSkipFalseyValueFallsThroughPastSkipGuard(): void
    {
        $messages = [];
        $mount = sys_get_temp_dir().'/pmss-bootstrap-quota-'.bin2hex(random_bytes(4));

        $this->withEnv([
            'PMSS_SKIP_QUOTA' => 'no',
            'PMSS_QUOTA_MOUNT' => $mount,
        ], function () use (&$messages): void {
            \pmssConfigureQuotaMount(function (string $message) use (&$messages): void {
                $messages[] = $message;
            });
        });

        $this->assertTrue(
            !$this->messagesContain($messages, 'Quota configuration skipped via PMSS_SKIP_QUOTA'),
            'falsey PMSS_SKIP_QUOTA must not trigger skip'
        );
        $this->assertTrue(
            $this->messagesContain($messages, 'Skipping remount for '.$mount.' (mount path not found)'),
            'falsey PMSS_SKIP_QUOTA should fall through to normal quota handling'
        );
    }

    private function withEnv(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $key => $value) {
            $previous[$key] = getenv($key);
            if ($value === null) {
                putenv($key);
                continue;
            }
            putenv($key.'='.$value);
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === false) {
                    putenv($key);
                    continue;
                }
                putenv($key.'='.$value);
            }
        }
    }

    private function messagesContain(array $messages, string $needle): bool
    {
        foreach ($messages as $message) {
            if (strpos($message, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
