<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/trafficLimit.php';

/** Locks trafficLimitThrottle.php and trafficLimitReconcile.php with observations and actions stubbed. */
class TrafficLimitRecoveryCharacterizationTest extends TestCase
{
    public function testCooldownUsesTheSameAgeBoundaryForBothAssertionSources(): void
    {
        $script = <<<'PHP'
namespace TrafficCooldownFixture;
function pmssTrafficLimitMarkerPathIsSafe($path) { $GLOBALS['events'][] = 'safe'; return $GLOBALS['safe']; }
function pmssPathExistsOrLink($path) { $GLOBALS['events'][] = 'exists'; return $GLOBALS['marker']; }
function pmssTrafficLimitThrottleFilePath($user, $root) { $GLOBALS['events'][] = ['path', $user, $root]; return $GLOBALS['path']; }
function pmssRegularFilePathIsReadable($path) { $GLOBALS['events'][] = ['readable', $path]; return $GLOBALS['readable']; }
function filemtime($path) { $GLOBALS['events'][] = ['mtime', $path]; return $GLOBALS['mtime']; }
function time() { $GLOBALS['events'][] = 'time'; return 1000; }
function pmssTrafficLimitLog($user, $message) { $GLOBALS['events'][] = ['log', $user, $message]; }
function pmssTrafficLimitThrottleOrphanReconcile(...$args) { $GLOBALS['events'][] = ['orphan', $args]; return $GLOBALS['action']; }
function pmssTrafficLimitThrottleLiftAfterCooldown(...$args) { $GLOBALS['events'][] = ['lift', $args]; return $GLOBALS['action']; }
PHP;
        $script .= \pmssTestingFunctionExport('pmssTrafficLimitThrottleReconcileWithoutUsage', 'TrafficCooldownFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_TRAFFIC_COOLDOWN'), true) as $case) {
    foreach ($case as $key => $value) $GLOBALS[$key] = $value;
    $GLOBALS['events'] = [];
    $result = pmssTrafficLimitThrottleReconcileWithoutUsage('fixtur01', '/fixture/marker', 100, '/fixture/home');
    $results[] = [$result, $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $base = ['safe' => true, 'marker' => true, 'path' => '/fixture/display', 'readable' => true, 'mtime' => 899, 'action' => true];
        $cases = [array_replace($base, ['safe' => false])];
        $expected = [[false, ['safe', ['log', 'fixtur01', 'traffic throttle marker path unsafe (/fixture/marker)']]]];
        foreach ([true, false] as $marker) {
            foreach ([false, 1001, 1000, 901, 900, 899, 0] as $mtime) {
                foreach ([true, false] as $action) {
                    $cases[] = array_replace($base, compact('marker', 'mtime', 'action'));
                    $path = $marker ? '/fixture/marker' : '/fixture/display';
                    $events = ['safe', 'exists'];
                    if (!$marker) $events = array_merge($events, [['path', 'fixtur01', '/fixture/home'], ['readable', $path]]);
                    $events[] = ['mtime', $path];
                    if ($mtime !== false) $events[] = 'time';
                    $expired = $mtime !== false && $mtime < 900;
                    if ($expired) $events[] = $marker
                        ? ['lift', ['fixtur01', '/fixture/marker', 'traffic throttle removed after cooldown (no usage data)', '/fixture/home']]
                        : ['orphan', ['fixtur01', 0, '/fixture/marker', '/fixture/home']];
                    $expected[] = [$expired ? $action : true, $events];
                }
            }
        }
        foreach ([['path' => null], ['readable' => false]] as $override) {
            $cases[] = array_replace($base, ['marker' => false], $override);
            $events = ['safe', 'exists', ['path', 'fixtur01', '/fixture/home']];
            if (!array_key_exists('path', $override)) $events[] = ['readable', '/fixture/display'];
            $expected[] = [true, $events];
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_TRAFFIC_COOLDOWN' => json_encode($cases)]));
    }

    public function testCooldownLiftRetainsTwoAttemptsAndSecondResult(): void
    {
        $script = <<<'PHP'
namespace TrafficLiftFixture;
function pmssTrafficLimitMarkerRemove($user, $path) { $GLOBALS['events'][] = ['remove', $user, $path]; return $GLOBALS['remove']; }
function pmssTrafficLimitLog($user, $message) { $GLOBALS['events'][] = ['log', $user, $message]; }
function pmssTrafficLimitThrottleApply(...$args) { $GLOBALS['events'][] = ['apply', $args]; return array_shift($GLOBALS['attempts']); }
function sleep($seconds) { $GLOBALS['events'][] = ['sleep', $seconds]; }
PHP;
        $script .= \pmssTestingFunctionExport('pmssTrafficLimitThrottleLiftAfterCooldown', 'TrafficLiftFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([false, true] as $remove) foreach ([false, true] as $first) foreach ([false, true] as $second) {
    $GLOBALS['remove'] = $remove; $GLOBALS['attempts'] = [$first, $second]; $GLOBALS['events'] = [];
    $result = pmssTrafficLimitThrottleLiftAfterCooldown('fixtur01', '/fixture/marker', 'fixture reason', '/fixture/home');
    $results[] = [$result, $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $expected = [];
        foreach ([false, true] as $remove) foreach ([false, true] as $first) foreach ([false, true] as $second) {
            $events = [['remove', 'fixtur01', '/fixture/marker']];
            if ($remove) $events = array_merge($events, [['log', 'fixtur01', 'fixture reason'],
                ['apply', ['fixtur01', 0, false, '/fixture/home']], ['sleep', 1], ['apply', ['fixtur01', 0, false, '/fixture/home']]]);
            $expected[] = [$remove && $second, $events];
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }

    public function testApplyRetainsActionResultsAndFailureMessages(): void
    {
        $script = <<<'PHP'
namespace TrafficApplyFixture;
function pmssTrafficLimitThrottleFilePath($user, $root) { $GLOBALS['events'][] = ['path', $user, $root]; return $GLOBALS['path']; }
function pmssTrafficLimitThrottleFileWrite($path, $cap, &$error) { $GLOBALS['events'][] = ['write', $path, $cap, $error]; $error = $GLOBALS['error']; return $GLOBALS['ok']; }
function pmssTrafficLimitThrottleFileRemove($path, &$error) { $GLOBALS['events'][] = ['remove', $path, $error]; $error = $GLOBALS['error']; return $GLOBALS['ok']; }
function pmssTrafficLimitLog($user, $message) { $GLOBALS['events'][] = ['log', $user, $message]; }
PHP;
        $script .= \pmssTestingFunctionExport('pmssTrafficLimitThrottleApply', 'TrafficApplyFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_TRAFFIC_APPLY'), true) as [$path, $enable, $ok, $error]) {
    $GLOBALS['path'] = $path; $GLOBALS['ok'] = $ok; $GLOBALS['error'] = $error; $GLOBALS['events'] = [];
    $result = pmssTrafficLimitThrottleApply('fixtur01', 25, $enable, '/fixture/home');
    $results[] = [$result, $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $cases = $expected = [];
        foreach ([null, '/fixture/display'] as $path) foreach ([false, true] as $enable) {
            foreach ([false, true] as $ok) foreach ([null, '', '0', 'fixture error'] as $error) {
                $cases[] = [$path, $enable, $ok, $error];
                $events = [['path', 'fixtur01', '/fixture/home']];
                if ($path !== null) {
                    $events[] = $enable ? ['write', $path, 25, null] : ['remove', $path, null];
                    if (!$ok) $events[] = ['log', 'fixtur01', 'traffic throttle file '.($enable ? 'write' : 'removal').' failed ('.($error ?: $path).')'];
                }
                $expected[] = [$path !== null && $ok, $events];
            }
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_TRAFFIC_APPLY' => json_encode($cases)]));
    }
}
