<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/logging.php';

class RsyslogRateLimitTest extends TestCase
{
    private const STOCK_CONFIG = "module(load=\"imuxsock\")\nmodule(load=\"imklog\")   # kernel input\n\$IncludeConfig /etc/rsyslog.d/*.conf\n";

    public function testStockDebianDeclarationGetsRateLimit(): void
    {
        $this->assertStringContainsAllStrings(['module(load="imklog" RatelimitInterval="10" RatelimitBurst="2000")',
            '$IncludeConfig /etc/rsyslog.d/*.conf' => 'foreign config content changed'], \pmssRsyslogKernelInputRateLimitConfig(self::STOCK_CONFIG));
    }

    public function testExistingRateLimitIsIdempotent(): void
    {
        $configured = \pmssRsyslogKernelInputRateLimitConfig(self::STOCK_CONFIG);
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
        $this->pmssWriteFile($target, self::STOCK_CONFIG);
        chmod($target, 0640);

        $this->pmssWithEnv([
            'PMSS_RSYSLOG_CONFIG_PATH' => $target,
            'PMSS_TEST_MODE' => '1',
        ], function () use (&$messages, &$commands): void {
            \pmssApplyRsyslogKernelInputRateLimit(
                $this->pmssMakeArrayLogger($messages),
                static function (string $description, string $command) use (&$commands): int {
                    $commands[] = [$description, $command];
                    preg_match('/-f (.+)$/', $command, $matches);
                    $candidate = trim($matches[1] ?? '', "'");
                    if ((fileperms($candidate) & 0777) !== 0600) {
                        throw new \RuntimeException('rsyslog candidate is not private');
                    }
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
        $this->assertEquals([], glob($root.'/.pmss-rsyslog-*') ?: [], 'candidate was not removed');
        $this->pmssAssertMessagesContain($messages, 'Applied rsyslog kernel input rate limit', 'expected apply log');
        $this->assertEquals(1, count(glob($target.'.pmss-backup-*') ?: []), 'expected one backup');
    }

    public function testValidationFailurePreservesOriginal(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rsyslog-invalid-');
        $target = $root.'/rsyslog.conf';
        $original = self::STOCK_CONFIG;
        $messages = [];
        $this->pmssWriteFile($target, $original);

        $this->pmssWithEnv([
            'PMSS_RSYSLOG_CONFIG_PATH' => $target,
            'PMSS_TEST_MODE' => '1',
        ], function () use (&$messages): void {
            \pmssApplyRsyslogKernelInputRateLimit(
                $this->pmssMakeArrayLogger($messages),
                self::pmssMakeCallbacks(['validate' => 1])['validate']
            );
        });

        $this->assertEquals($original, file_get_contents($target));
        $this->assertEquals([], glob($target.'.pmss-backup-*') ?: []);
        $this->pmssAssertMessagesContain($messages, 'candidate failed validation', 'expected validation warning');
    }

    public function testValidationExceptionRemovesCandidateAndPreservesOriginal(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rsyslog-exception-');
        $target = $root.'/rsyslog.conf';
        $original = self::STOCK_CONFIG;
        $this->pmssWriteFile($target, $original);

        try {
            $this->pmssWithEnv([
                'PMSS_RSYSLOG_CONFIG_PATH' => $target,
                'PMSS_TEST_MODE' => '1',
            ], function (): void {
                \pmssApplyRsyslogKernelInputRateLimit(
                    static function (string $message): void {},
                    static function (string $description, string $command): int {
                        throw new \RuntimeException('validation unavailable');
                    }
                );
            });
            $this->assertTrue(false, 'validation exception was swallowed');
        } catch (\RuntimeException $exception) {
            $this->assertEquals('validation unavailable', $exception->getMessage());
        }

        $this->assertEquals($original, file_get_contents($target));
        $this->assertEquals([], glob($root.'/.pmss-rsyslog-*') ?: []);
        $this->assertEquals([], glob($target.'.pmss-backup-*') ?: []);
    }

    public function testNonstandardConfigWarnsWithoutMutation(): void
    {
        $root = $this->pmssMakeTempDir('pmss-rsyslog-custom-');
        $target = $root.'/rsyslog.conf';
        $custom = "module(load=\"imklog\" ParseKernelTimestamp=\"on\")\n";
        $messages = [];
        $this->pmssWriteFile($target, $custom);

        $this->pmssWithEnv(['PMSS_RSYSLOG_CONFIG_PATH' => $target], function () use (&$messages): void {
            \pmssApplyRsyslogKernelInputRateLimit($this->pmssMakeArrayLogger($messages));
        });

        $this->assertEquals($custom, file_get_contents($target));
        $this->pmssAssertMessagesContain($messages, 'Preserving nonstandard rsyslog imklog configuration', 'expected preservation warning');
    }
}
