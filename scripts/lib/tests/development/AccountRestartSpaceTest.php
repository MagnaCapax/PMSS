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
                self::pmssMakeCallbacks(['freeBytes' => 0])['freeBytes']);
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
                    self::pmssMakeCallbacks(['freeBytes' => PMSS_ACCOUNT_RESTART_MIN_FREE_BYTES])['freeBytes']);
            }, 'lighttpd restart requested', 15,
            static function () use (&$stopped): void { $stopped = true; });

        $this->assertTrue($stopped);
        $this->assertFalse($running);
        $this->assertSame([], $warnings);
    }

    public function testLowSpaceBoundariesAndFailedProbe(): void
    {
        $measure = self::pmssMakeCallbacks(['freeBytes' => PMSS_ACCOUNT_RESTART_MIN_FREE_BYTES - 1])['freeBytes'];
        $this->assertFalse(pmssAccountRestartSpaceAvailable('/srv/pmss-test-home', $measure));
        $this->assertTrue(pmssAccountRestartSpaceAvailable('/srv/pmss-test-home', self::pmssMakeCallbacks(['freeBytes' => PMSS_ACCOUNT_RESTART_MIN_FREE_BYTES])['freeBytes']));
        $this->assertFalse(pmssAccountRestartSpaceAvailable('/srv/pmss-test-home', self::pmssMakeCallbacks(['freeBytes' => false])['freeBytes']));
        $this->assertFalse(pmssAccountRestartSpaceAvailable('/srv/pmss-test-home', self::pmssMakeCallbacks(['freeBytes' => 0])['freeBytes']));
    }
}
