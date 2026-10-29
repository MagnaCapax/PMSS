<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/watchdogSocketProbe.php';

/** Characterize both retry consumers without socket connections, commands or real sleeps. */
class LighttpdSocketRetryCharacterizationTest extends TestCase
{
    public function testConnectionAndListenerRetriesShareTheirLegacyCadence(): void
    {
        foreach ([
            [0, -1, [false], 1, []],
            [-2, 2, [false], 1, []],
            [4, 2, [true], 1, []],
            [4, 2, [false, true], 2, [2]],
            [3, 1, [false, false, true], 3, [1, 1]],
            [3, 0, [false, false, false], 3, []],
            [4, 2, [false, false, false, false], 4, [2, 2, 2]],
        ] as [$attempts, $delay, $observations, $calls, $sleeps]) {
            foreach (['connection', 'listeners'] as $kind) {
                $seen = [];
                $remaining = $observations;
                $options = ['attemptCount' => $attempts, 'retryDelaySeconds' => $delay,
                    'sleep' => static function ($seconds) use (&$seen): void { $seen[] = ['sleep', $seconds]; }];
                if ($kind === 'connection') {
                    $options['probe'] = static function ($path, $timeout) use (&$seen, &$remaining): array {
                        $seen[] = ['probe', $path, $timeout];
                        return ['ok' => array_shift($remaining), 'errno' => 111, 'errstr' => 'refused'];
                    };
                    $result = \pmssLighttpdWatchdogSocketProbeWithRetry('/socket', $options);
                    $expected = ['ok' => $observations[$calls - 1], 'errno' => 111, 'errstr' => 'refused', 'attempts' => $calls];
                } else {
                    $options['reader'] = static function () use (&$seen, &$remaining): array {
                        $seen[] = ['reader'];
                        return ['rc' => 0, 'lines' => array_shift($remaining)
                            ? ['u_str LISTEN 0 1024 /home/account/.lighttpd/php.socket-3 1 * 0'] : []];
                    };
                    $result = \pmssLighttpdWatchdogRestartVerify('/home/account', ['/expected'], $options);
                    $expected = ['status' => $observations[$calls - 1] ? 'healthy' : 'restart_attempted_still_down',
                        'attempts' => $calls, 'expected' => 1, 'observed' => $observations[$calls - 1] ? 1 : 0];
                }
                $trace = [];
                for ($i = 0; $i < $calls; $i++) {
                    $trace[] = $kind === 'connection' ? ['probe', '/socket', 5] : ['reader'];
                    if (isset($sleeps[$i])) $trace[] = ['sleep', $sleeps[$i]];
                }
                $this->assertSame([$expected, $trace], [$result, $seen], $kind);
            }
        }
    }

    public function testMalformedCallbackResultsAndFinalSnapshotStatusArePreserved(): void
    {
        foreach ([null, false, 'invalid', 0, []] as $payload) {
            $result = \pmssLighttpdWatchdogSocketProbeWithRetry('/socket', ['attemptCount' => 1,
                'probe' => static function () use ($payload) { return $payload; }]);
            $this->assertSame(['ok' => false, 'errno' => 0,
                'errstr' => is_array($payload) ? '' : 'probe callback returned invalid result', 'attempts' => 1], $result);
        }
        foreach ([[[124, 0], 'restart_attempted_still_down'], [[0, 124], 'restart_attempted_unverified']] as [$statuses, $status]) {
            $result = \pmssLighttpdWatchdogRestartVerify('/home/account', ['/expected'], ['attemptCount' => 2,
                'retryDelaySeconds' => 0, 'reader' => static function () use (&$statuses): array {
                    return ['rc' => array_shift($statuses), 'lines' => []];
                }]);
            $this->assertSame(['status' => $status,
                'attempts' => 2, 'expected' => 1, 'observed' => 0], $result);
        }
    }

    public function testRetryStopsAtTheOriginalProbeOrSleepThrowable(): void
    {
        foreach (['connection', 'listeners'] as $kind) {
            foreach (['probe', 'sleep'] as $stage) {
                foreach ([new \RuntimeException($stage), new \Error($stage)] as $failure) {
                    $calls = [];
                    $options = ['attemptCount' => 3, 'retryDelaySeconds' => 1,
                        'sleep' => static function () use (&$calls, $failure): void { $calls[] = 'sleep'; throw $failure; }];
                    $options[$kind === 'connection' ? 'probe' : 'reader'] = static function () use (&$calls, $failure, $stage, $kind): array {
                        $calls[] = 'probe';
                        if ($stage === 'probe') throw $failure;
                        return $kind === 'connection' ? ['ok' => false] : ['rc' => 0, 'lines' => []];
                    };
                    $caught = null;
                    try {
                        if ($kind === 'connection') \pmssLighttpdWatchdogSocketProbeWithRetry('/socket', $options);
                        else \pmssLighttpdWatchdogRestartVerify('/home/account', ['/expected'], $options);
                    } catch (\Throwable $error) { $caught = $error; }
                    $this->assertSame($failure, $caught);
                    $this->assertSame($stage === 'probe' ? ['probe'] : ['probe', 'sleep'], $calls);
                }
            }
        }
    }

    public function testNativeConnectionSuccessAlsoRequiresCleanErrorFieldsAndClosesTheStream(): void
    {
        $script = <<<'PHP'
namespace SocketTransportFixture;
function fsockopen($path, $port, &$errno, &$errstr, $timeout) {
    $GLOBALS['args'] = [$path, $port, $timeout];
    [$open, $errno, $errstr] = $GLOBALS['case'];
    return $GLOBALS['handle'] = $open ? \fopen('php://temp', 'w+') : false;
}
function fclose($handle) { $GLOBALS['closed']++; return \fclose($handle); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/watchdogSocketProbe.php', 'SocketTransportFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([[true, 0, ''], [false, 111, 'refused'], [true, 111, ''], [true, 0, 'warning'], [false, 0, ''], [true, '0', '']] as $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['closed'] = 0;
    $results[] = [pmssLighttpdWatchdogSocketConnect('/socket', 7), $GLOBALS['args'], $GLOBALS['closed'], is_resource($GLOBALS['handle'])];
}
echo json_encode($results);
PHP;
        $results = $this->pmssRunInlinePhpJson($script);
        foreach ([[true, 0, '', 1], [false, 111, 'refused', 0], [false, 111, '', 1],
            [false, 0, 'warning', 1], [false, 0, '', 0], [false, 0, '', 1]] as $i => $case) {
            $this->assertSame([['ok' => $case[0], 'errno' => $case[1], 'errstr' => $case[2]],
                ['unix:///socket', 0, 7], $case[3], false], $results[$i]);
        }
    }
}
