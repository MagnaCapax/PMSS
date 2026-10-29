<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/watchdog.php';

/** Freeze watchdog records and dispatch order without starting account services. */
class UserWatchdogBoundariesCharacterizationTest extends TestCase
{
    public function testServiceSpecsKeepKeyOrderAndOptionalValueSemantics(): void
    {
        foreach ([
            ['', [], ['processName' => 'demo', 'command' => 'start', 'userLogMessage' => 'requested']],
            ['Demo', [], ['processName' => 'demo', 'serviceLabel' => 'Demo', 'command' => 'start', 'userLogMessage' => 'requested']],
            ['', ['unknown' => true], ['processName' => 'demo', 'command' => 'start', 'userLogMessage' => 'requested']],
            ['', ['crashLoop' => 'demo', 'securedMarker' => '.secured'], ['processName' => 'demo', 'command' => 'start',
                'userLogMessage' => 'requested', 'securedMarker' => '.secured', 'crashLoop' => 'demo']],
            ['Demo', ['securedMarker' => null, 'crashLoop' => null], ['processName' => 'demo', 'serviceLabel' => 'Demo',
                'command' => 'start', 'userLogMessage' => 'requested']],
            ['', ['crashLoop' => false, 'securedMarker' => ''], ['processName' => 'demo', 'command' => 'start',
                'userLogMessage' => 'requested', 'securedMarker' => '', 'crashLoop' => false]],
        ] as [$label, $options, $expected]) {
            $this->assertSame($expected, \pmssUserWatchdogServiceSpec('demo', 'start', 'requested', $label, $options));
        }
    }

    public function testCrashTransitionsKeepResetPrecedenceAndUnmodifiedState(): void
    {
        $state = ['pending' => true, 'restart' => 'old', 'failures' => 4, 'extra' => 'keep'];
        foreach ([
            [$state, false, 'old', ['pending' => false, 'restart' => 'old', 'failures' => 5, 'extra' => 'keep']],
            [$state, false, '', ['pending' => false, 'restart' => 'old', 'failures' => 5, 'extra' => 'keep']],
            [$state, false, 'new', ['failures' => 0, 'pending' => false, 'restart' => 'new']],
            [$state, true, 'old', ['failures' => 0, 'pending' => false, 'restart' => 'old']],
            [$state, true, '', ['failures' => 0, 'pending' => false, 'restart' => '']],
            [['pending' => false, 'failures' => '4'], false, '', ['pending' => false, 'failures' => '4']],
            [[], false, '', []],
            [['pending' => true], false, '', ['pending' => false, 'failures' => 1]],
        ] as [$before, $running, $token, $after]) {
            $this->assertSame($after, \pmssUserWatchdogCrashTransition($before, $running, $token));
        }
    }

    public function testSharedServicePatternKeepsExactBoundsAndCharacterRules(): void
    {
        foreach (['a', 'demo', 'a0-b', str_repeat('a', 32)] as $name) {
            $this->assertSame(1, preg_match(PMSS_USER_WATCHDOG_SERVICE_PATTERN, $name));
        }
        foreach (['', '0demo', 'Demo', 'demo_app', '../demo', "demo\n", "demo\0", str_repeat('a', 33)] as $name) {
            $this->assertSame(0, preg_match(PMSS_USER_WATCHDOG_SERVICE_PATTERN, $name));
            $this->assertSame(null, \pmssUserWatchdogBackoffPath('fixture', $name));
            $this->assertSame(['storageError' => true], \pmssUserWatchdogCrashState('fixture', $name));
            if ($name !== '') $this->assertFalse(\pmssUserWatchdogGatedStartAllowed('fixture', '/unused', ['crashLoop' => $name], false));
        }
    }

    public function testDispatchKeepsObservationCallbacksGatesAndOutputInOrder(): void
    {
        $script = <<<'PHP'
namespace UserWatchdogDispatchFixture;
function pmssValidateUsername($name) { return $name === 'fixture'; }
function pmssUserWatchdogProcessRunning($user, $service) { $GLOBALS['events'][] = 'probe'; return false; }
function pmssUserWatchdogBackoffActive($user, $service) { $GLOBALS['events'][] = 'backoff'; return $GLOBALS['case']['backoff']; }
function pmssUserWatchdogGatedStartAllowed($user, $home, $spec, $running) { $GLOBALS['events'][] = ['gate', $home, $running]; return $GLOBALS['case']['allowed']; }
function pmssUserWatchdogGatedStartRecord($user, $spec) { $GLOBALS['events'][] = 'record'; return $GLOBALS['case']['record']; }
function pmssUserWatchdogCommandRun($command, $seconds, $user, $service) { $GLOBALS['events'][] = [$command, $seconds, $user, $service]; return ['rc' => 7]; }
function pmssUserLog($user, $message) { $GLOBALS['events'][] = ['log', $user, $message]; }
function pmssResolvePathFromEnv($key, $default) { return '/fixture-home'; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/watchdog.php', 'UserWatchdogDispatchFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([[], ['running' => true], ['backoff' => true], ['allowed' => false], ['record' => false], ['empty' => true], ['discover' => true]] as $case) {
    $GLOBALS['case'] = $case + ['running' => false, 'backoff' => false, 'allowed' => true, 'record' => true, 'empty' => false];
    $GLOBALS['events'] = [];
    $command = static function ($user) { $GLOBALS['events'][] = ['build', $user]; return $GLOBALS['case']['empty'] ? '' : 'start'; };
    ob_start();
    $states = pmssUserWatchdogEnsureServices('fixture', [[], pmssUserWatchdogServiceSpec('demo', $command, 'requested', 'Demo')],
        !empty($case['discover']) ? [] : ['demo' => $GLOBALS['case']['running']]);
    $results[] = [$states, ob_get_clean(), $GLOBALS['events']];
}
echo json_encode($results);
PHP;
        $gate = ['gate', '/fixture-home/fixture', false];
        $start = ['backoff', ['build', 'fixture'], $gate, 'backoff', 'record',
            ['start', 90, 'fixture', 'demo'], ['log', 'fixture', 'requested']];
        $this->assertSame([
            [['demo' => false], "Start Demo for user: fixture\n", $start],
            [['demo' => true], '', ['backoff', ['build', 'fixture'], ['gate', '/fixture-home/fixture', true]]],
            [['demo' => false], '', ['backoff']],
            [['demo' => false], '', ['backoff', ['build', 'fixture'], $gate]],
            [['demo' => false], '', ['backoff', ['build', 'fixture'], $gate, 'backoff', 'record']],
            [['demo' => false], '', ['backoff', ['build', 'fixture'], $gate]],
            [['demo' => false], "Start Demo for user: fixture\n", array_merge(['probe'], $start)],
        ], $this->pmssRunInlinePhpJson($script));
    }
}
