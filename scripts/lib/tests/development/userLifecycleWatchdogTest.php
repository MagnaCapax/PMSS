<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';

class UserLifecycleWatchdogTest extends TestCase
{
    public function testManagedConfigAppliesOnlyWhenServiceIsStopped(): void
    {
        $calledFor = [];

        $applied = pmssUserWatchdogApplyManagedConfigWhenStopped(
            'alice',
            false,
            static function (string $username) use (&$calledFor): bool {
                $calledFor[] = $username;
                return true;
            }
        );

        $this->assertTrue($applied);
        $this->assertEquals(['alice'], $calledFor);
    }

    public function testManagedConfigSkipsRunningMissingAndInvalidTargets(): void
    {
        $called = false;
        $applier = static function () use (&$called): bool {
            $called = true;
            return true;
        };

        $this->assertFalse(pmssUserWatchdogApplyManagedConfigWhenStopped('alice', true, $applier));
        $this->assertFalse(pmssUserWatchdogApplyManagedConfigWhenStopped('alice', false, 'pmssMissingConfigApplierForTest'));
        $this->assertFalse(pmssUserWatchdogApplyManagedConfigWhenStopped('bad-user', false, $applier));
        $this->assertFalse($called);
    }

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

        list($states,) = $this->pmssCaptureStdout(function () use ($marker): array {
            return pmssUserWatchdogEnsureServices('alice', [
                pmssUserWatchdogServiceSpec('demo', 'touch '.escapeshellarg($marker), 'demo start requested', 'Demo'),
            ], ['demo' => false]);
        });

        $this->assertTrue(file_exists($marker));
        $this->assertEquals(['demo' => false], $states);
    }

    public function testEnsureServicesSkipsStartForRunningServiceFromProvidedState(): void
    {
        $marker = $this->pmssMakeTempFile('watchdog-skip-');
        @unlink($marker);

        list($states,) = $this->pmssCaptureStdout(function () use ($marker): array {
            return pmssUserWatchdogEnsureServices('alice', [
                pmssUserWatchdogServiceSpec('demo', 'touch '.escapeshellarg($marker), 'demo start requested', 'Demo'),
            ], ['demo' => true]);
        });

        $this->assertFalse(file_exists($marker));
        $this->assertEquals(['demo' => true], $states);
    }

    public function testEnsureServicesEvaluatesCommandCallbacks(): void
    {
        $marker = $this->pmssMakeTempFile('watchdog-callback-');
        @unlink($marker);

        $this->pmssCaptureStdout(function () use ($marker): void {
            pmssUserWatchdogEnsureServices('alice', [
                pmssUserWatchdogServiceSpec('demo', static function () use ($marker): string {
                    return 'touch '.escapeshellarg($marker);
                }, 'demo start requested', 'Demo'),
            ], ['demo' => false]);
        });

        $this->assertTrue(file_exists($marker));
    }

    public function testEnsureServicesRefusesInvalidUsernameBeforeCommand(): void
    {
        $marker = $this->pmssMakeTempFile('watchdog-invalid-');
        @unlink($marker);

        list($states,) = $this->pmssCaptureStdout(function () use ($marker): array {
            return pmssUserWatchdogEnsureServices('bad-user', [
                pmssUserWatchdogServiceSpec('demo', 'touch '.escapeshellarg($marker), 'demo start requested', 'Demo'),
            ], ['demo' => false]);
        });

        $this->assertFalse(file_exists($marker));
        $this->assertEquals(['demo' => false], $states);
    }

    public function testTerminateProcessesSeparatesKillallOptionsFromProcessNames(): void
    {
        $log = $this->pmssMakeTempFile('watchdog-killall-');
        @file_put_contents($log, '');
        $binDir = $this->pmssMakeInvocationLogStub('killall', $log, 'watchdog-killall-bin-');

        $this->pmssWithPathPrefix($binDir, function (): void {
            pmssUserWatchdogTerminateProcesses('alice', ['demo', '-TERM', 'php-cgi', "bad\nname"], 15);
        });

        $lines = file($log, FILE_IGNORE_NEW_LINES);
        $this->assertEquals(['-15 -u alice -- demo', '-15 -u alice -- php-cgi'], $lines);
    }

    public function testRunServiceStartsOnlyEnabledStoppedUsers(): void
    {
        $homeRoot = $this->pmssMakeTempDir('watchdog-run-service-');
        foreach (['alice', 'bob', 'cara'] as $user) {
            $this->pmssEnsureUserWebHome($homeRoot, $user);
        }
        touch($homeRoot.'/alice/.demoEnable');
        touch($homeRoot.'/cara/.demoEnable');

        $listUsers = $this->pmssMakeTempDir('watchdog-list-users-').'/listUsers.php';
        $this->pmssWriteExecutablePhpFile($listUsers, "echo \"alice\\nbob\\ncara\\n\";");

        $marker = $this->pmssMakeTempFile('watchdog-run-service-started-');
        @unlink($marker);

        list(, $output) = $this->pmssCaptureStdout(function () use ($homeRoot, $listUsers, $marker): void {
            pmssUserWatchdogRunService(
                '',
                'demoEnable',
                ['demo'],
                'demo stopped due to suspension',
                [
                    pmssUserWatchdogServiceSpec('demo', static function (string $username) use ($marker): string {
                        return 'echo '.escapeshellarg($username).' >> '.escapeshellarg($marker);
                    }, 'demo start requested', 'Demo'),
                ],
                static function (string $username): array {
                    return ['demo' => $username === 'cara'];
                },
                null,
                $homeRoot,
                $listUsers
            );
        });

        $this->assertEquals("alice\n", (string) file_get_contents($marker));
        $this->assertStringContainsString('Start Demo for user: alice', $output);
        $this->assertStringNotContainsString('Start Demo for user: bob', $output);
        $this->assertStringNotContainsString('Start Demo for user: cara', $output);
    }

    public function testRunServiceStopsWhenListUsersCommandFails(): void
    {
        $homeRoot = $this->pmssMakeTempDir('watchdog-run-service-fail-');
        $this->pmssEnsureUserWebHome($homeRoot, 'alice');
        touch($homeRoot.'/alice/.demoEnable');

        $listUsers = $this->pmssMakeTempDir('watchdog-list-users-fail-').'/listUsers.php';
        $this->pmssWriteExecutablePhpFile($listUsers, "echo \"alice\\n\"; exit(1);");

        $marker = $this->pmssMakeTempFile('watchdog-run-service-blocked-');
        @unlink($marker);

        list(, $output) = $this->pmssCaptureStdout(function () use ($homeRoot, $listUsers, $marker): void {
            pmssUserWatchdogRunService(
                '',
                'demoEnable',
                ['demo'],
                'demo stopped due to suspension',
                [
                    pmssUserWatchdogServiceSpec('demo', 'touch '.escapeshellarg($marker), 'demo start requested', 'Demo'),
                ],
                static function (): array {
                    return ['demo' => false];
                },
                null,
                $homeRoot,
                $listUsers
            );
        });

        $this->assertFalse(file_exists($marker));
        $this->assertEquals('', $output);
    }

    public function testLocalPortReadAcceptsTrimmedNumericPort(): void
    {
        $path = $this->pmssMakeTempFile('watchdog-port-');
        file_put_contents($path, "1500\n");

        $this->assertSame(1500, pmssUserWatchdogLocalPortRead($path));
    }

    public function testLocalPortReadRejectsUnsafeInputs(): void
    {
        $missing = $this->pmssMakeTempPath('watchdog-port-missing-');
        $path = $this->pmssMakeTempFile('watchdog-port-bad-');
        file_put_contents($path, '1500; touch /tmp/bad');
        $outOfRange = $this->pmssMakeTempFile('watchdog-port-high-');
        file_put_contents($outOfRange, '65536');
        $target = $this->pmssMakeTempFile('watchdog-port-target-');
        file_put_contents($target, '1500');
        $link = $this->pmssMakeTempPath('watchdog-port-link-');
        symlink($target, $link);

        foreach ([$missing, $path, $outOfRange, $link] as $candidate) {
            $this->assertSame(null, pmssUserWatchdogLocalPortRead($candidate));
        }
    }
}
