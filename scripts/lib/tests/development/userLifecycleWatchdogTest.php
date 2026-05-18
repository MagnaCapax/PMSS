<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/userLifecycle.php';

class UserLifecycleWatchdogTest extends TestCase
{
    public function testRestartProcessesIfSkipsCallbackWhenServiceIsStopped(): void
    {
        $called = false;

        $running = pmssUserWatchdogRestartProcessesIf(
            'alice',
            false,
            ['demo'],
            static function () use (&$called): bool {
                $called = true;
                return true;
            },
            'demo restart requested'
        );

        $this->assertFalse($called);
        $this->assertFalse($running);
    }

    public function testRestartProcessesIfUsesCustomTerminatorWhenRestartNeeded(): void
    {
        $terminated = false;

        $running = pmssUserWatchdogRestartProcessesIf(
            'alice',
            true,
            ['demo'],
            static function (): bool {
                return true;
            },
            'demo restart requested',
            15,
            static function () use (&$terminated): void {
                $terminated = true;
            }
        );

        $this->assertTrue($terminated);
        $this->assertFalse($running);
    }

    public function testRestartProcessesIfRefusesInvalidUsernameBeforeTerminator(): void
    {
        $terminated = false;

        $running = pmssUserWatchdogRestartProcessesIf(
            'bad-user',
            true,
            ['demo'],
            static function (): bool {
                return true;
            },
            'demo restart requested',
            15,
            static function () use (&$terminated): void {
                $terminated = true;
            }
        );

        $this->assertFalse($terminated);
        $this->assertTrue($running);
    }

    public function testRestartProcessesIfLeavesRunningStateWhenRestartNotNeeded(): void
    {
        $running = pmssUserWatchdogRestartProcessesIf(
            'alice',
            true,
            ['demo'],
            static function (): bool {
                return false;
            },
            'demo restart requested'
        );

        $this->assertTrue($running);
    }

    public function testEnsureServicesStartsStoppedServiceFromProvidedState(): void
    {
        $marker = $this->pmssMakeTempFile('watchdog-start-');
        @unlink($marker);

        ob_start();
        $states = pmssUserWatchdogEnsureServices('alice', [
            [
                'processName' => 'demo',
                'serviceLabel' => 'Demo',
                'command' => 'touch '.escapeshellarg($marker),
                'userLogMessage' => 'demo start requested',
            ],
        ], ['demo' => false]);
        ob_end_clean();

        $this->assertTrue(file_exists($marker));
        $this->assertEquals(['demo' => false], $states);
    }

    public function testEnsureServicesSkipsStartForRunningServiceFromProvidedState(): void
    {
        $marker = $this->pmssMakeTempFile('watchdog-skip-');
        @unlink($marker);

        ob_start();
        $states = pmssUserWatchdogEnsureServices('alice', [
            [
                'processName' => 'demo',
                'serviceLabel' => 'Demo',
                'command' => 'touch '.escapeshellarg($marker),
                'userLogMessage' => 'demo start requested',
            ],
        ], ['demo' => true]);
        ob_end_clean();

        $this->assertFalse(file_exists($marker));
        $this->assertEquals(['demo' => true], $states);
    }

    public function testEnsureServicesEvaluatesCommandCallbacks(): void
    {
        $marker = $this->pmssMakeTempFile('watchdog-callback-');
        @unlink($marker);

        ob_start();
        pmssUserWatchdogEnsureServices('alice', [
            [
                'processName' => 'demo',
                'serviceLabel' => 'Demo',
                'command' => static function () use ($marker): string {
                    return 'touch '.escapeshellarg($marker);
                },
                'userLogMessage' => 'demo start requested',
            ],
        ], ['demo' => false]);
        ob_end_clean();

        $this->assertTrue(file_exists($marker));
    }

    public function testEnsureServicesRefusesInvalidUsernameBeforeCommand(): void
    {
        $marker = $this->pmssMakeTempFile('watchdog-invalid-');
        @unlink($marker);

        ob_start();
        $states = pmssUserWatchdogEnsureServices('bad-user', [
            [
                'processName' => 'demo',
                'serviceLabel' => 'Demo',
                'command' => 'touch '.escapeshellarg($marker),
                'userLogMessage' => 'demo start requested',
            ],
        ], ['demo' => false]);
        ob_end_clean();

        $this->assertFalse(file_exists($marker));
        $this->assertEquals(['demo' => false], $states);
    }

    public function testLocalPortReadAcceptsTrimmedNumericPort(): void
    {
        $path = $this->pmssMakeTempFile('watchdog-port-');
        file_put_contents($path, "1500\n");

        $this->assertSame(1500, pmssUserWatchdogLocalPortRead($path));
    }

    public function testLocalPortReadRejectsMissingFile(): void
    {
        $path = $this->pmssMakeTempPath('watchdog-port-missing-');

        $this->assertSame(null, pmssUserWatchdogLocalPortRead($path));
    }

    public function testLocalPortReadRejectsMalformedFile(): void
    {
        $path = $this->pmssMakeTempFile('watchdog-port-bad-');
        file_put_contents($path, '1500; touch /tmp/bad');

        $this->assertSame(null, pmssUserWatchdogLocalPortRead($path));
    }

    public function testLocalPortReadRejectsOutOfRangePort(): void
    {
        $path = $this->pmssMakeTempFile('watchdog-port-high-');
        file_put_contents($path, '65536');

        $this->assertSame(null, pmssUserWatchdogLocalPortRead($path));
    }

    public function testLocalPortReadRejectsSymlink(): void
    {
        $target = $this->pmssMakeTempFile('watchdog-port-target-');
        file_put_contents($target, '1500');
        $link = $this->pmssMakeTempPath('watchdog-port-link-');
        symlink($target, $link);

        $this->assertSame(null, pmssUserWatchdogLocalPortRead($link));
    }
}
