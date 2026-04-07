<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/watchdogSocketProbe.php';
require_once __DIR__.'/../common/TestCase.php';

class LighttpdWatchdogSocketProbeTest extends TestCase
{
    public function testProbeSucceedsOnFirstAttemptWithoutSleeping(): void
    {
        $probeCalls = 0;
        $sleepCalls = array();

        $result = \pmssLighttpdWatchdogSocketProbeWithRetry('/tmp/php.socket-0', array(
            'probe' => static function (string $socketPath, int $timeoutSeconds) use (&$probeCalls): array {
                $probeCalls++;
                return array('ok' => true, 'errno' => 0, 'errstr' => '');
            },
            'sleep' => static function (int $seconds) use (&$sleepCalls): void {
                $sleepCalls[] = $seconds;
            },
        ));

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertEquals(1, $probeCalls);
        $this->assertEquals(array(), $sleepCalls);
    }

    public function testProbeRetriesBeforeDeclaringFailure(): void
    {
        $probeCalls = 0;
        $sleepCalls = array();

        $result = \pmssLighttpdWatchdogSocketProbeWithRetry('/tmp/php.socket-0', array(
            'probe' => static function (string $socketPath, int $timeoutSeconds) use (&$probeCalls): array {
                $probeCalls++;
                if ($probeCalls === 1) {
                    return array('ok' => false, 'errno' => 111, 'errstr' => 'Connection refused');
                }

                return array('ok' => true, 'errno' => 0, 'errstr' => '');
            },
            'sleep' => static function (int $seconds) use (&$sleepCalls): void {
                $sleepCalls[] = $seconds;
            },
        ));

        $this->assertTrue($result['ok']);
        $this->assertEquals(2, $result['attempts']);
        $this->assertEquals(2, $probeCalls);
        $this->assertEquals(array(1), $sleepCalls);
    }

    public function testProbeReturnsLastFailureWhenAllAttemptsFail(): void
    {
        $probeCalls = 0;
        $sleepCalls = array();

        $result = \pmssLighttpdWatchdogSocketProbeWithRetry('/tmp/php.socket-0', array(
            'probe' => static function (string $socketPath, int $timeoutSeconds) use (&$probeCalls): array {
                $probeCalls++;

                return array('ok' => false, 'errno' => 111 + $probeCalls, 'errstr' => 'Connection refused '.$probeCalls);
            },
            'sleep' => static function (int $seconds) use (&$sleepCalls): void {
                $sleepCalls[] = $seconds;
            },
        ));

        $this->assertFalse($result['ok']);
        $this->assertEquals(2, $result['attempts']);
        $this->assertEquals(2, $probeCalls);
        $this->assertEquals(113, $result['errno']);
        $this->assertEquals('Connection refused 2', $result['errstr']);
        $this->assertEquals(array(1), $sleepCalls);
    }

    public function testProbeCoercesAttemptCountBelowOneToSingleAttempt(): void
    {
        $probeCalls = 0;

        $result = \pmssLighttpdWatchdogSocketProbeWithRetry('/tmp/php.socket-0', array(
            'attemptCount' => 0,
            'probe' => static function (string $socketPath, int $timeoutSeconds) use (&$probeCalls): array {
                $probeCalls++;

                return array('ok' => false, 'errno' => 111, 'errstr' => 'Connection refused');
            },
            'sleep' => static function (): void {
            },
        ));

        $this->assertFalse($result['ok']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertEquals(1, $probeCalls);
    }

    public function testProbeSkipsSleepWhenRetryDelayIsZero(): void
    {
        $probeCalls = 0;
        $sleepCalls = array();

        $result = \pmssLighttpdWatchdogSocketProbeWithRetry('/tmp/php.socket-0', array(
            'retryDelaySeconds' => 0,
            'probe' => static function (string $socketPath, int $timeoutSeconds) use (&$probeCalls): array {
                $probeCalls++;
                if ($probeCalls === 1) {
                    return array('ok' => false, 'errno' => 111, 'errstr' => 'Connection refused');
                }

                return array('ok' => true, 'errno' => 0, 'errstr' => '');
            },
            'sleep' => static function (int $seconds) use (&$sleepCalls): void {
                $sleepCalls[] = $seconds;
            },
        ));

        $this->assertTrue($result['ok']);
        $this->assertEquals(2, $result['attempts']);
        $this->assertEquals(2, $probeCalls);
        $this->assertEquals(array(), $sleepCalls);
    }

    public function testProbeRejectsEmptySocketPathImmediately(): void
    {
        $probeCalls = 0;

        $result = \pmssLighttpdWatchdogSocketProbeWithRetry('', array(
            'probe' => static function (string $socketPath, int $timeoutSeconds) use (&$probeCalls): array {
                $probeCalls++;

                return array('ok' => true, 'errno' => 0, 'errstr' => '');
            },
            'sleep' => static function (): void {
            },
        ));

        $this->assertFalse($result['ok']);
        $this->assertEquals(1, $result['attempts']);
        $this->assertEquals(0, $probeCalls);
        $this->assertEquals('socket path missing', $result['errstr']);
    }
}
