<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/watchdogSocketProbe.php';
require_once __DIR__.'/../common/TestCase.php';

class LighttpdWatchdogSocketProbeTest extends TestCase
{
    public function testDefaultRetryPolicyConstantsAreExpanded(): void
    {
        $this->assertSame(4, \PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_ATTEMPTS);
        $this->assertSame(3, \PMSS_LIGHTTPD_WATCHDOG_SOCKET_FAILURE_CYCLES);
        $this->assertSame(2, \PMSS_LIGHTTPD_WATCHDOG_SOCKET_PROBE_RETRY_DELAY_SECONDS);
        $this->assertSame(111, \PMSS_LIGHTTPD_WATCHDOG_SOCKET_ECONNREFUSED);
    }

    /**
     * Run the socket probe against scripted probe responses and captured sleeps.
     *
     * @param array<int,array<string,mixed>> $probeResults
     * @param array<string,mixed> $options
     * @return array{0:array<string,mixed>,1:int,2:array<int,int>}
     */
    private function runSocketProbeFixture(array $probeResults, array $options = array()): array
    {
        $probeCalls = 0;
        $sleepCalls = array();
        $socketPath = isset($options['socketPath']) ? (string) $options['socketPath'] : '/tmp/php.socket-0';
        unset($options['socketPath']);

        $result = \pmssLighttpdWatchdogSocketProbeWithRetry($socketPath, $options + array(
            'probe' => static function (string $socketPath, int $timeoutSeconds) use (&$probeCalls, $probeResults): array {
                $probeCalls++;
                $probeResult = $probeResults[min($probeCalls - 1, max(0, count($probeResults) - 1))] ?? array();

                return array(
                    'ok' => !empty($probeResult['ok']),
                    'errno' => isset($probeResult['errno']) ? (int) $probeResult['errno'] : 0,
                    'errstr' => isset($probeResult['errstr']) ? (string) $probeResult['errstr'] : '',
                );
            },
            'sleep' => static function (int $seconds) use (&$sleepCalls): void {
                $sleepCalls[] = $seconds;
            },
        ));

        return array($result, $probeCalls, $sleepCalls);
    }

    private function socketFailureOptions(string $runtimeDir, int $threshold): array
    {
        return array('runtimeDir' => $runtimeDir, 'threshold' => $threshold);
    }

    private function recordSocketFailure(string $runtimeDir, int $threshold, string $username = 'alice'): array
    {
        return \pmssLighttpdWatchdogRecordSocketFailure($username, $this->socketFailureOptions($runtimeDir, $threshold));
    }

    public function testProbeSucceedsOnFirstAttemptWithoutSleeping(): void
    {
        [$result, $probeCalls, $sleepCalls] = $this->runSocketProbeFixture([
            array('ok' => true, 'errno' => 0, 'errstr' => ''),
        ]);

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertEquals(1, $probeCalls);
        $this->assertEquals(array(), $sleepCalls);
    }

    public function testProbeRetriesBeforeDeclaringFailure(): void
    {
        [$result, $probeCalls, $sleepCalls] = $this->runSocketProbeFixture([
            array('ok' => false, 'errno' => 111, 'errstr' => 'Connection refused'),
            array('ok' => true, 'errno' => 0, 'errstr' => ''),
        ]);

        $this->assertTrue($result['ok']);
        $this->assertEquals(2, $result['attempts']);
        $this->assertEquals(2, $probeCalls);
        $this->assertEquals(array(2), $sleepCalls);
    }

    public function testProbeReturnsLastFailureWhenAllAttemptsFail(): void
    {
        [$result, $probeCalls, $sleepCalls] = $this->runSocketProbeFixture([
            array('ok' => false, 'errno' => 112, 'errstr' => 'Connection refused 1'),
            array('ok' => false, 'errno' => 113, 'errstr' => 'Connection refused 2'),
            array('ok' => false, 'errno' => 114, 'errstr' => 'Connection refused 3'),
            array('ok' => false, 'errno' => 115, 'errstr' => 'Connection refused 4'),
        ]);

        $this->assertFalse($result['ok']);
        $this->assertEquals(4, $result['attempts']);
        $this->assertEquals(4, $probeCalls);
        $this->assertEquals(115, $result['errno']);
        $this->assertEquals('Connection refused 4', $result['errstr']);
        $this->assertEquals(array(2, 2, 2), $sleepCalls);
    }

    public function testProbeCoercesAttemptCountBelowOneToSingleAttempt(): void
    {
        [$result, $probeCalls] = $this->runSocketProbeFixture([
            array('ok' => false, 'errno' => 111, 'errstr' => 'Connection refused'),
        ], array('attemptCount' => 0));

        $this->assertFalse($result['ok']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertEquals(1, $probeCalls);
    }

    public function testProbeSkipsSleepWhenRetryDelayIsZero(): void
    {
        [$result, $probeCalls, $sleepCalls] = $this->runSocketProbeFixture([
            array('ok' => false, 'errno' => 111, 'errstr' => 'Connection refused'),
            array('ok' => true, 'errno' => 0, 'errstr' => ''),
        ], array('retryDelaySeconds' => 0));

        $this->assertTrue($result['ok']);
        $this->assertEquals(2, $result['attempts']);
        $this->assertEquals(2, $probeCalls);
        $this->assertEquals(array(), $sleepCalls);
    }

    public function testProbeRejectsEmptySocketPathImmediately(): void
    {
        [$result, $probeCalls] = $this->runSocketProbeFixture([
            array('ok' => true, 'errno' => 0, 'errstr' => ''),
        ], array('socketPath' => ''));

        $this->assertFalse($result['ok']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertEquals(0, $probeCalls);
        $this->assertEquals('socket path missing', $result['errstr']);
    }

    public function testListeningSocketParserAcceptsCurrentNumberedAndLegacyPaths(): void
    {
        $home = '/home/alice';
        $this->assertSame(
            array($home.'/.lighttpd/php.socket-1', $home.'/.lighttpd/php.socket'),
            \pmssLighttpdWatchdogSocketPathsFromLines(array(
                'u_str LISTEN 0 1024 '.$home.'/.lighttpd/php.socket-1 12345 * 0',
                'u_str LISTEN 0 1024 '.$home.'/.lighttpd/php.socket 12346 * 0',
            ), $home)
        );
    }

    public function testListeningSocketParserRejectsUntrustedRowsAndPaths(): void
    {
        $home = '/home/alice';
        $this->assertSame(array(), \pmssLighttpdWatchdogSocketPathsFromLines(array(
            'u_str ESTAB 0 1024 '.$home.'/.lighttpd/php.socket-1 12345 * 0',
            'u_str LISTEN nope 1024 '.$home.'/.lighttpd/php.socket-2 12346 * 0',
            'u_str LISTEN 0 nope '.$home.'/.lighttpd/php.socket-3 12347 * 0',
            'u_str LISTEN 0 1024 /home/bob/.lighttpd/php.socket-1 12348 * 0',
            'u_str LISTEN 0 1024 '.$home.'/.lighttpd/php.socket-old 12349 * 0',
            'malformed',
        ), $home));
        $this->assertSame(array(), \pmssLighttpdWatchdogSocketPathsFromLines(array(), 'relative/home'));
    }

    public function testListeningSocketParserDeduplicatesListenerRows(): void
    {
        $path = '/home/alice/.lighttpd/php.socket-2';
        $line = 'u_str LISTEN 0 1024 '.$path.' 12345 * 0';

        $this->assertSame(
            array($path),
            \pmssLighttpdWatchdogSocketPathsFromLines(array($line, $line), '/home/alice')
        );
    }

    public function testListeningSocketReaderRejectsFailedAndMalformedResults(): void
    {
        foreach (array(
            static function (): array { return array('lines' => array(), 'rc' => 1); },
            static function (): array { return array('lines' => 'not-an-array', 'rc' => 0); },
            static function () { return 'not-an-array'; },
        ) as $reader) {
            $this->assertSame(
                array('ok' => false, 'paths' => array()),
                \pmssLighttpdWatchdogListeningSocketSnapshot('/home/alice', array('reader' => $reader))
            );
        }
    }

    public function testListeningSocketReaderReturnsStrictlyParsedPaths(): void
    {
        $path = '/home/alice/.lighttpd/php.socket-4';
        $reader = static function () use ($path): array {
            return array('lines' => array('u_str LISTEN 0 1024 '.$path.' 12345 * 0'), 'rc' => 0);
        };

        $this->assertSame(
            array('ok' => true, 'paths' => array($path)),
            \pmssLighttpdWatchdogListeningSocketSnapshot('/home/alice', array('reader' => $reader))
        );
    }

    public function testListeningSocketSnapshotPreservesProbeStatus(): void
    {
        $path = '/home/alice/.lighttpd/php.socket-1';
        $healthyReader = static function () use ($path): array {
            return array('lines' => array('u_str LISTEN 0 1024 '.$path.' 12345 * 0'), 'rc' => 0);
        };
        $failedReader = static function (): array {
            return array('lines' => array(), 'rc' => 124);
        };

        $this->assertSame(
            array('ok' => true, 'paths' => array($path)),
            \pmssLighttpdWatchdogListeningSocketSnapshot('/home/alice', array('reader' => $healthyReader))
        );
        $this->assertSame(
            array('ok' => false, 'paths' => array()),
            \pmssLighttpdWatchdogListeningSocketSnapshot('/home/alice', array('reader' => $failedReader))
        );
    }

    public function testListenerCoverageRequiresConfiguredCount(): void
    {
        $this->assertFalse(\pmssLighttpdWatchdogListenerCoverageIsHealthy(array(), array('/socket-1')));
        $this->assertFalse(\pmssLighttpdWatchdogListenerCoverageIsHealthy(array('/socket-0'), array()));
        $this->assertFalse(\pmssLighttpdWatchdogListenerCoverageIsHealthy(array('/socket-0', '/socket-1'), array('/socket-2')));
        $this->assertTrue(\pmssLighttpdWatchdogListenerCoverageIsHealthy(array('/socket-0'), array('/socket-2')));
        $this->assertTrue(\pmssLighttpdWatchdogListenerCoverageIsHealthy(array('/socket-0', '/socket-1'), array('/socket-2', '/socket-3')));
        $this->assertFalse(\pmssLighttpdWatchdogListenerCoverageIsHealthy(array('/socket-0', '/socket-1'), array('/socket-2', '/socket-2')));
    }

    public function testRestartVerificationRetriesAndReportsOutcome(): void
    {
        $calls = 0;
        $sleeps = array();
        $reader = static function () use (&$calls): array {
            $calls++;
            $lines = $calls < 2 ? array() : array('u_str LISTEN 0 1024 /home/alice/.lighttpd/php.socket-4 12345 * 0');
            return array('lines' => $lines, 'rc' => 0);
        };
        $sleep = static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        };

        $this->assertSame(
            array('status' => 'healthy', 'attempts' => 2, 'expected' => 1, 'observed' => 1),
            \pmssLighttpdWatchdogRestartVerify('/home/alice', array('/socket-0'), array(
                'attemptCount' => 3,
                'retryDelaySeconds' => 1,
                'reader' => $reader,
                'sleep' => $sleep,
            ))
        );
        $this->assertSame(array(1), $sleeps);
    }

    public function testRestartVerificationDistinguishesDownFromUnverified(): void
    {
        $noListeners = static function (): array { return array('lines' => array(), 'rc' => 0); };
        $probeFailure = static function (): array { return array('lines' => array(), 'rc' => 124); };
        $options = array('attemptCount' => 1, 'retryDelaySeconds' => 0);

        $this->assertSame(
            array('status' => 'restart_attempted_still_down', 'attempts' => 1, 'expected' => 1, 'observed' => 0),
            \pmssLighttpdWatchdogRestartVerify('/home/alice', array('/socket-0'), $options + array('reader' => $noListeners))
        );
        $this->assertSame(
            array('status' => 'restart_attempted_unverified', 'attempts' => 1, 'expected' => 1, 'observed' => 0),
            \pmssLighttpdWatchdogRestartVerify('/home/alice', array('/socket-0'), $options + array('reader' => $probeFailure))
        );
    }

    public function testStaleIndexDecisionRequiresRefusalAndConfiguredWorkerCount(): void
    {
        $expected = array('/socket-0', '/socket-1');
        $this->assertFalse(\pmssLighttpdWatchdogSocketFailureIsStaleIndex(111, array(), array('/socket-2')));
        $this->assertFalse(\pmssLighttpdWatchdogSocketFailureIsStaleIndex(111, $expected, array()));
        $this->assertFalse(\pmssLighttpdWatchdogSocketFailureIsStaleIndex(111, $expected, array('/socket-2')));
        $this->assertFalse(\pmssLighttpdWatchdogSocketFailureIsStaleIndex(110, $expected, array('/socket-2', '/socket-3')));
        $this->assertTrue(\pmssLighttpdWatchdogSocketFailureIsStaleIndex(111, $expected, array('/socket-2', '/socket-3')));
        $this->assertTrue(\pmssLighttpdWatchdogSocketFailureIsStaleIndex(
            111,
            array('/socket-0', '/socket-0'),
            array('/socket-2', '/socket-2')
        ));
    }

    public function testSocketFailureStateWaitsUntilThreshold(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('lighttpd-socket-state-');

        $first = $this->recordSocketFailure($runtimeDir, 3);
        $second = $this->recordSocketFailure($runtimeDir, 3);
        $third = $this->recordSocketFailure($runtimeDir, 3);

        $this->assertSame(array('action' => 'wait', 'count' => 1, 'threshold' => 3), $first);
        $this->assertSame(array('action' => 'wait', 'count' => 2, 'threshold' => 3), $second);
        $this->assertSame(array('action' => 'restart', 'count' => 3, 'threshold' => 3), $third);
    }

    public function testSocketFailureStateClearsAfterHealthyProbe(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('lighttpd-socket-clear-');
        $options = $this->socketFailureOptions($runtimeDir, 2);

        $this->assertSame(
            array('action' => 'wait', 'count' => 1, 'threshold' => 2),
            \pmssLighttpdWatchdogRecordSocketFailure('alice', $options)
        );
        \pmssLighttpdWatchdogClearSocketFailure('alice', $options);
        $this->assertSame(
            array('action' => 'wait', 'count' => 1, 'threshold' => 2),
            \pmssLighttpdWatchdogRecordSocketFailure('alice', $options)
        );
    }

    public function testSocketFailureStatePreservesRegularMarkerContract(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('lighttpd-socket-regular-');
        $statePath = \pmssLighttpdWatchdogSocketFailureStatePath('alice', $runtimeDir);
        file_put_contents($statePath, '8');
        chmod($statePath, 0640);

        $this->assertSame(
            array('action' => 'wait', 'count' => 9, 'threshold' => 10),
            $this->recordSocketFailure($runtimeDir, 10)
        );
        $this->assertSame('9', file_get_contents($statePath));
        $this->assertSame(
            array('action' => 'restart', 'count' => 10, 'threshold' => 10),
            $this->recordSocketFailure($runtimeDir, 10)
        );
        $this->assertSame('10', file_get_contents($statePath));
        clearstatcache(true, $statePath);
        $this->assertSame(0640, fileperms($statePath) & 0777);
    }

    public function testSocketFailureStateRejectsSymlinkMarkersWithoutChangingTargets(): void
    {
        // Cover both an existing foreign file and a dangling link's absent target.
        foreach (array(false, true) as $targetExists) {
            $runtimeDir = $this->pmssMakeTempDir('lighttpd-socket-symlink-');
            $statePath = \pmssLighttpdWatchdogSocketFailureStatePath('alice', $runtimeDir);
            $target = $runtimeDir.'/foreign';
            if ($targetExists) {
                file_put_contents($target, 'sentinel');
            }
            $this->assertTrue(symlink($target, $statePath));

            $this->assertSame('', \pmssLighttpdWatchdogSocketFailureStatePath('alice', $runtimeDir));
            $this->assertSame(
                array('action' => 'wait', 'count' => 0, 'threshold' => 1),
                $this->recordSocketFailure($runtimeDir, 1)
            );
            \pmssLighttpdWatchdogClearSocketFailure('alice', $this->socketFailureOptions($runtimeDir, 1));
            $this->assertSame($target, readlink($statePath));
            $this->assertSame($targetExists, file_exists($target));
            if ($targetExists) {
                $this->assertSame('sentinel', file_get_contents($target));
            }
        }
    }

    public function testSocketFailureStateRejectsNonRegularMarkers(): void
    {
        foreach (array('directory', 'fifo') as $type) {
            $runtimeDir = $this->pmssMakeTempDir('lighttpd-socket-type-');
            $statePath = \pmssLighttpdWatchdogSocketFailureStatePath('alice', $runtimeDir);
            if ($type === 'directory') {
                $this->assertTrue(mkdir($statePath));
            } else {
                if (!function_exists('posix_mkfifo')) {
                    continue;
                }
                $this->assertTrue(posix_mkfifo($statePath, 0600));
            }

            // Reject the FIFO before file_put_contents can block waiting for a reader.
            $this->assertSame('', \pmssLighttpdWatchdogSocketFailureStatePath('alice', $runtimeDir));
            $this->assertSame(
                array('action' => 'wait', 'count' => 0, 'threshold' => 1),
                $this->recordSocketFailure($runtimeDir, 1)
            );
            \pmssLighttpdWatchdogClearSocketFailure('alice', $this->socketFailureOptions($runtimeDir, 1));
            $this->assertSame($type === 'directory' ? 'dir' : 'fifo', filetype($statePath));
        }
    }

    public function testSocketFailureStateCreatesMissingRuntimeDirectory(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('lighttpd-socket-missing-').'/runtime';
        $this->assertSame(
            array('action' => 'wait', 'count' => 1, 'threshold' => 3),
            $this->recordSocketFailure($runtimeDir, 3)
        );
        $statePath = \pmssLighttpdWatchdogSocketFailureStatePath('alice', $runtimeDir);
        $this->assertSame('1', file_get_contents($statePath));
    }

    public function testSocketFailureStateRejectsInvalidUsername(): void
    {
        $runtimeDir = $this->pmssMakeTempDir('lighttpd-socket-invalid-');

        $this->assertSame('', \pmssLighttpdWatchdogSocketFailureStatePath('bad/user', $runtimeDir));
        $this->assertSame(
            array('action' => 'wait', 'count' => 0, 'threshold' => 2),
            $this->recordSocketFailure($runtimeDir, 2, 'bad/user')
        );
    }

    public function testSocketFailureStateRejectsUnsafeRuntimeDir(): void
    {
        $this->assertSame('', \pmssLighttpdWatchdogSocketFailureStatePath('alice', 'relative/runtime'));
        $this->assertSame('', \pmssLighttpdWatchdogSocketFailureStatePath('alice', '/'));
    }
}
