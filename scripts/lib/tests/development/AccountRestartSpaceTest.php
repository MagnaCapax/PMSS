<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/restartSpace.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';

class AccountRestartSpaceTest extends TestCase
{
    public function testFullHomeKeepsRunningServiceDespiteFailedStartStub(): void
    {
        $running = true;
        $warnings = [];
        $starts = 0;
        $start = static function () use (&$starts): bool {
            $starts++;
            return false; // Stub the ENOSPC start failure on a full home.
        };
        $restart = static function () use (&$running, &$warnings): bool {
            return pmssAccountRestartSpaceAllows('alice', '/srv/pmss-test-home', 'automatic lighttpd restart',
                static function (string $line) use (&$warnings): void { $warnings[] = $line; },
                static function (): int { return 0; });
        };
        $running = pmssUserWatchdogRestartProcessesIf('alice', $running, ['lighttpd', 'php-cgi'], $restart,
            'lighttpd restart requested', 15,
            static function () use (&$running): void { $running = false; });
        if (!$running) $running = $start();

        $this->assertTrue($running);
        $this->assertSame(0, $starts);
        $this->assertSame(1, count($warnings));
        $this->assertTrue(strpos($warnings[0], '[WARN] Account alice:') === 0);
        $this->assertStringContainsString('automatic lighttpd restart', $warnings[0]);
    }

    public function testNormalSpaceKeepsExistingRestartBehavior(): void
    {
        $stopped = false;
        $warnings = [];
        $running = pmssUserWatchdogRestartProcessesIf('alice', true, ['lighttpd', 'php-cgi'],
            static function () use (&$warnings): bool {
                return pmssAccountRestartSpaceAllows('alice', '/srv/pmss-test-home', 'automatic lighttpd restart',
                    static function (string $line) use (&$warnings): void { $warnings[] = $line; },
                    static function (): int { return PMSS_ACCOUNT_RESTART_MIN_FREE_BYTES; });
            }, 'lighttpd restart requested', 15,
            static function () use (&$stopped): void { $stopped = true; });

        $this->assertTrue($stopped);
        $this->assertFalse($running);
        $this->assertSame([], $warnings);
    }

    public function testLowSpaceBoundariesAndFailedProbe(): void
    {
        $measure = static function (string $home): int { return PMSS_ACCOUNT_RESTART_MIN_FREE_BYTES - 1; };
        $this->assertFalse(pmssAccountRestartSpaceAvailable('/srv/pmss-test-home', $measure));
        $this->assertTrue(pmssAccountRestartSpaceAvailable('/srv/pmss-test-home', static function (): int {
            return PMSS_ACCOUNT_RESTART_MIN_FREE_BYTES;
        }));
        $this->assertFalse(pmssAccountRestartSpaceAvailable('/srv/pmss-test-home', static function () { return false; }));
        $this->assertFalse(pmssAccountRestartSpaceAvailable('/srv/pmss-test-home', static function (): int { return 0; }));
    }
}
