<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/logging.php';

class RsyslogRateLimitTest extends TestCase
{
    private function stockConfig(): string
    {
        return "module(load=\"imuxsock\")\nmodule(load=\"imklog\")   # kernel input\n\$IncludeConfig /etc/rsyslog.d/*.conf\n";
    }

    public function testStockDebianDeclarationGetsRateLimit(): void
    {
        $rendered = \pmssRsyslogKernelInputRateLimitConfig($this->stockConfig());
        $this->assertTrue(strpos($rendered, 'module(load="imklog" RatelimitInterval="10" RatelimitBurst="2000")') !== false);
        $this->assertTrue(strpos($rendered, '$IncludeConfig /etc/rsyslog.d/*.conf') !== false, 'foreign config content changed');
    }

    public function testExistingRateLimitIsIdempotent(): void
    {
        $configured = \pmssRsyslogKernelInputRateLimitConfig($this->stockConfig());
        $this->assertEquals($configured, \pmssRsyslogKernelInputRateLimitConfig($configured));
    }

    public function testCustomImklogDeclarationIsPreserved(): void
    {
        $custom = "module(load=\"imklog\" ParseKernelTimestamp=\"on\")\n";
        $this->assertEquals($custom, \pmssRsyslogKernelInputRateLimitConfig($custom));
    }

    public function testApplyValidatesBacksUpAndPreservesMetadata(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rsyslog-rate-limit-');
        $target = $root.'/rsyslog.conf';
        $messages = [];
        $commands = [];
        file_put_contents($target, $this->stockConfig());
        chmod($target, 0640);

        $this->pmssWithEnv([
            'PMSS_RSYSLOG_CONFIG_PATH' => $target,
            'PMSS_TEST_MODE' => '1',
        ], function () use ($target, &$messages, &$commands): void {
            \pmssApplyRsyslogKernelInputRateLimit(
                $this->pmssMakeArrayLogger($messages),
                static function (string $description, string $command) use (&$commands): int {
                    $commands[] = [$description, $command];
                    return 0;
                }
            );
        });

        $this->pmssAssertFileContainsAllStrings($target, [
            'RatelimitInterval="10"', 'RatelimitBurst="2000"', '$IncludeConfig /etc/rsyslog.d/*.conf',
        ], 'validated rsyslog config missing: ');
        $this->assertEquals(0640, fileperms($target) & 0777, 'rsyslog config mode changed');
        $this->assertEquals(1, count($commands));
        $this->assertTrue(strpos($commands[0][1], 'rsyslogd -N1 -f ') !== false, 'candidate was not validated');
        $this->pmssAssertMessagesContain($messages, 'Applied rsyslog kernel input rate limit', 'expected apply log');
        $this->assertEquals(1, count(glob($target.'.pmss-backup-*') ?: []), 'expected one backup');
    }

    public function testValidationFailurePreservesOriginal(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rsyslog-invalid-');
        $target = $root.'/rsyslog.conf';
        $original = $this->stockConfig();
        $messages = [];
        file_put_contents($target, $original);

        $this->pmssWithEnv([
            'PMSS_RSYSLOG_CONFIG_PATH' => $target,
            'PMSS_TEST_MODE' => '1',
        ], function () use (&$messages): void {
            \pmssApplyRsyslogKernelInputRateLimit(
                $this->pmssMakeArrayLogger($messages),
                static function (): int { return 1; }
            );
        });

        $this->assertEquals($original, file_get_contents($target));
        $this->assertEquals([], glob($target.'.pmss-backup-*') ?: []);
        $this->pmssAssertMessagesContain($messages, 'candidate failed validation', 'expected validation warning');
    }

    public function testNonstandardConfigWarnsWithoutMutation(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rsyslog-custom-');
        $target = $root.'/rsyslog.conf';
        $custom = "module(load=\"imklog\" ParseKernelTimestamp=\"on\")\n";
        $messages = [];
        file_put_contents($target, $custom);

        $this->pmssWithEnv(['PMSS_RSYSLOG_CONFIG_PATH' => $target], function () use (&$messages): void {
            \pmssApplyRsyslogKernelInputRateLimit($this->pmssMakeArrayLogger($messages));
        });

        $this->assertEquals($custom, file_get_contents($target));
        $this->pmssAssertMessagesContain($messages, 'Preserving nonstandard rsyslog imklog configuration', 'expected preservation warning');
    }
}
