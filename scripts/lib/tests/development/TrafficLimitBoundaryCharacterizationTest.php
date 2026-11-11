<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/trafficLimit.php';

/** Locks trafficLimitMarkers.php and trafficLimitAccount.php without real accounts or writes. */
class TrafficLimitBoundaryCharacterizationTest extends TestCase
{
    public function testMarkerTouchRetainsGuardWriteAndChmodOrdering(): void
    {
        $script = <<<'PHP'
namespace TrafficMarkerFixture;
function pmssTrafficLimitMarkerPathIsSafe($path) { $GLOBALS['events'][] = 'safe'; return $GLOBALS['safe']; }
function pmssIntegerSettingFileWrite($path, $value) { $GLOBALS['events'][] = ['write', $path, $value]; return $GLOBALS['written']; }
function touch($path) { $GLOBALS['events'][] = ['touch', $path]; return $GLOBALS['written']; }
function chmod($path, $mode) { $GLOBALS['events'][] = ['chmod', $path, $mode]; return $GLOBALS['mode']; }
function pmssTrafficLimitLog($user, $message) { $GLOBALS['events'][] = ['log', $user, $message]; }
PHP;
        $script .= \pmssTestingFunctionExport('pmssTrafficLimitMarkerTouch', 'TrafficMarkerFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([false, true] as $safe) foreach ([false, true] as $written) foreach ([false, true] as $mode) {
    foreach ([null, 0, 25] as $cap) {
        $GLOBALS['safe'] = $safe; $GLOBALS['written'] = $written; $GLOBALS['mode'] = $mode; $GLOBALS['events'] = [];
        $result = pmssTrafficLimitMarkerTouch('fixtur01', '/fixture/marker', $cap);
        $results[] = [$result, $GLOBALS['events']];
    }
}
echo json_encode($results);
PHP;
        $expected = [];
        foreach ([false, true] as $safe) foreach ([false, true] as $written) foreach ([false, true] as $mode) {
            foreach ([null, 0, 25] as $cap) {
                $events = ['safe'];
                if (!$safe) $events[] = ['log', 'fixtur01', 'traffic throttle marker path unsafe (/fixture/marker)'];
                else {
                    $events[] = $cap === null ? ['touch', '/fixture/marker'] : ['write', '/fixture/marker', $cap];
                    if (!$written) $events[] = ['log', 'fixtur01', 'traffic throttle marker touch failed (/fixture/marker)'];
                    else {
                        $events[] = ['chmod', '/fixture/marker', 0600];
                        if (!$mode) $events[] = ['log', 'fixtur01', 'traffic throttle marker chmod failed (/fixture/marker)'];
                    }
                }
                $expected[] = [$safe && $written, $events];
            }
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script));
    }

    public function testAccountRefusalsAndHomeFallbackRetainMessagesAndExitCodes(): void
    {
        $script = <<<'PHP'
namespace TrafficAccountFixture;
function function_exists($name) { return in_array($name, ['pmssNormalizeUsername', 'posix_geteuid'], true); }
function pmssNormalizeUsername($raw) { return strtolower(trim($raw)); }
function pmssTrafficLimitCliUsernameNormalize($raw) { $raw = pmssNormalizeUsername($raw); return preg_match('/^[a-z][a-z0-9]{0,7}$/D', $raw) ? $raw : null; }
function posix_geteuid() { $GLOBALS['events'][] = 'euid'; return $GLOBALS['uid']; }
function pmssTrafficLimitCliUserAccountLookup($user) { $GLOBALS['events'][] = ['lookup', $user]; return $GLOBALS['account']; }
function is_dir($path) { $GLOBALS['events'][] = ['dir', $path]; return $GLOBALS['dir']; }
function is_link($path) { $GLOBALS['events'][] = ['link', $path]; return $GLOBALS['link']; }
function fwrite($stream, $text) { $GLOBALS['events'][] = ['stderr', $text]; return strlen($text); }
PHP;
        $script .= \pmssTestingFunctionExport('pmssTrafficLimitResolveCliUserHome', 'TrafficAccountFixture');
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_TRAFFIC_ACCOUNT'), true) as [$raw, $uid, $account, $dir, $link]) {
    $GLOBALS['uid'] = $uid; $GLOBALS['account'] = $account; $GLOBALS['dir'] = $dir; $GLOBALS['link'] = $link; $GLOBALS['events'] = [];
    $exit = 99;
    $result = pmssTrafficLimitResolveCliUserHome($raw, 'fixture usage', $exit);
    $results[] = [$result, $exit, $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $account = ['dir' => '/fixture/home'];
        $cases = [
            ['  ', 0, $account, true, false], ['BAD-NAME', 0, $account, true, false],
            ['fixtur01', 1001, $account, true, false], ['fixtur01', 0, $account, false, false],
            ['fixtur01', 0, $account, true, true], [' FIXTUR01 ', 0, $account, true, false],
            ['fixtur01', 0, null, true, false], ['fixtur01', 0, ['uid' => 1001], true, false],
        ];
        $expected = [
            [null, 2, [['stderr', "Error: missing username.\nfixture usage\n"]]],
            [null, 2, [['stderr', "Error: invalid username: bad-name\n"]]],
            [null, 1, ['euid', ['stderr', "Error: must run as root.\n"]]],
            [null, 3, ['euid', ['lookup', 'fixtur01'], ['dir', '/fixture/home'], ['stderr', "Error: no such user: fixtur01\n"]]],
            [null, 3, ['euid', ['lookup', 'fixtur01'], ['dir', '/fixture/home'], ['link', '/fixture/home'], ['stderr', "Error: no such user: fixtur01\n"]]],
        ];
        foreach (['/fixture/home', '/home/fixtur01', '/home/fixtur01'] as $home) {
            $expected[] = [['user' => 'fixtur01', 'home' => $home], null,
                ['euid', ['lookup', 'fixtur01'], ['dir', $home], ['link', $home]]];
        }
        $this->assertSame($expected, $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_TRAFFIC_ACCOUNT' => json_encode($cases)]));
    }
}
