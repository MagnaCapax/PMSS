<?php
/** Characterize scripts/lib/user/userConfigPrepare.php without account or service mutations. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigRuntime.php';

class UserConfigPrepareCharacterizationTest extends TestCase
{
    public function testModesPersistenceAndRefusalsRetainTheirTrace(): void
    {
        $script = 'namespace UserConfigPrepareFixture;';
        foreach (['user/userConfigCli.php', 'cli/optionParser.php', 'user/bonusQuota.php', 'user/userConfigRuntime.php'] as $file) {
            $script .= 'require_once '.var_export(dirname(__DIR__, 2).'/'.$file, true).';';
        }
        $script .= <<<'PHP'
class FixtureExit extends \RuntimeException {}
class UserConfigStore {
    function get($user) { $GLOBALS['events'][] = 'get'; return $GLOBALS['existing']; }
    function persist($user, $payload) {
        $GLOBALS['events'][] = 'persist'; $GLOBALS['payload'] = $payload;
        return $GLOBALS['case'] !== 'persist-failed';
    }
    function applyFallbacks($user, $payload) { throw new \RuntimeException('Unexpected billing fallback'); }
}
function fixtureDie($message) { echo $message; throw new FixtureExit('', 0); }
function fixtureExit($status) { throw new FixtureExit('', $status); }
function pmssCliExitWithStderr($message, $status) { $GLOBALS['stderr'] .= $message; fixtureExit($status); }
function fwrite($handle, $message) { $GLOBALS['stderr'] .= $message; return strlen($message); }
function function_exists($name) { return $name === 'pmssUserLog' || \function_exists($name); }
function pmssUserLog($user, $message) { $GLOBALS['events'][] = ['log', $user, $message]; }
function pmssPasswdEntryLookup($user) {
    $GLOBALS['events'][] = 'passwd';
    return $GLOBALS['case'] === 'missing' ? null : ['uid' => 1500, 'dir' => '/home/'.$user.'/'];
}
function pmssUserAccountLookup($user) {
    $GLOBALS['events'][] = 'account'; return ['uid' => $GLOBALS['case'] === 'system' ? 999 : 1500];
}
function file_exists($path) { $GLOBALS['events'][] = 'home'; return $GLOBALS['case'] !== 'no-home'; }
function pmssWelcomeUserMessageSet($user, $home, $message) { $GLOBALS['events'][] = 'welcome'; return true; }
function pmssUserConfigApplyGpuPolicy($user, $existing, $payload) { $GLOBALS['events'][] = 'gpu'; }
function pmssWriteTorrentThrottle($user, $cap) { $GLOBALS['events'][] = ['throttle', $cap]; return true; }
function runStep($label, $command) {
    $GLOBALS['events'][] = [$label, $command]; return $GLOBALS['case'] === 'refresh-failed' ? 1 : 0;
}
PHP;
        foreach (['pmssUserConfigPrepare', 'pmssUserConfigWelcomeOnlyPersist'] as $function) {
            $script .= \pmssTestingFunctionExport($function, 'UserConfigPrepareFixture', [
                'die(' => 'fixtureDie(', 'exit(0)' => 'fixtureExit(0)',
                'InvalidArgumentException' => '\\InvalidArgumentException',
            ]);
        }
        $script .= <<<'PHP'
$cases = ['full' => ['1024', '200'], 'named' => ['', '', '--io-weight=300'],
    'welcome' => ['', '', '--welcome-message=<p>Fixture</p>'],
    'persist-failed' => ['1024', '200'], 'refresh-failed' => ['1024', '200', '--lighttpd-min-threads=18'],
    'clear' => ['1024', '200', '--upload-throttle-kib=0'],
    'missing' => ['1024', '200'], 'system' => ['1024', '200'], 'no-home' => ['1024', '200'],
    'invalid-toggle' => ['1024', '200', '--docker-enabled=invalid'],
    'invalid-floor' => ['1024', '200', '--lighttpd-min-threads=-1']];
$rows = [];
foreach ($cases as $GLOBALS['case'] => $args) {
    $GLOBALS['events'] = []; $GLOBALS['stderr'] = ''; $GLOBALS['payload'] = null;
    $GLOBALS['existing'] = ['ramMiB' => 512, 'quota' => 100, 'quotaBurst' => 125, 'rtorrentPort' => 5000,
        'billingServiceId' => 1, 'billingClientId' => 2, 'IOWeight' => 100, 'uploadThrottleKib' => 64];
    $status = 0; $result = null; ob_start();
    try { $result = pmssUserConfigPrepare(array_merge(['userConfig.php', 'fixtur01'], $args)); }
    catch (FixtureExit $exit) { $status = $exit->getCode(); }
    $rows[$GLOBALS['case']] = [$status, ob_get_clean(), $GLOBALS['stderr'], $GLOBALS['events'],
        $GLOBALS['payload'], $result === null ? null : [$result['configMode'], $result['user']['memory'], $result['user']['quota']]];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $prefix = ['passwd', 'account', 'home', 'get'];
        foreach (['full' => ['full', 1024, 200], 'named' => ['named', 512, 100], 'clear' => ['full', 1024, 200]] as $case => $result) {
            $this->assertSame([0, '', ''], array_slice($rows[$case], 0, 3), $case);
            $this->assertSame($result, $rows[$case][5], $case);
            $events = array_merge($prefix, ['persist', 'gpu']);
            if ($case === 'clear') $events[] = ['throttle', 0];
            $this->assertSame($events, $rows[$case][3], $case);
        }
        $this->pmssAssertArraySubsetSame(['ramMiB' => 1024, 'quota' => 200, 'quotaBurst' => 250,
            'rtorrentPort' => 5000, 'IOWeight' => 100, 'uploadThrottleKib' => 64], $rows['full'][4]);
        $this->assertSame(300, $rows['named'][4]['IOWeight']);
        $this->assertFalse(array_key_exists('uploadThrottleKib', $rows['clear'][4]));
        $this->pmssAssertArraySubsetSame([3 => array_merge($prefix, ['welcome', 'persist']), 5 => null], $rows['welcome']);
        $this->assertSame([1, '', "Error: failed to persist user config for fixtur01\n",
            array_merge($prefix, ['persist', ['log', 'fixtur01', 'userConfig: user store write failed; no settings applied']])],
            array_slice($rows['persist-failed'], 0, 4));
        $this->assertSame([1, '', "Error: lighttpd config refresh failed; worker floor saved, rerun userConfig.php\n",
            array_merge($prefix, ['persist', ['Configuring lighttpd worker floor',
                "/scripts/util/userConfigLighttpd.php '--strict' 'fixtur01'"]])], array_slice($rows['refresh-failed'], 0, 4));
        $this->assertSame(18, $rows['refresh-failed'][4]['lighttpdMinThreads']);
        foreach (['missing' => ["No such user in passwd list\n", ['passwd']],
            'system' => ["No system ID or user does not exist\n", ['passwd', 'account']],
            'no-home' => ["User does not exist\n", ['passwd', 'account', 'home']],
            'invalid-toggle' => ["Invalid --docker-enabled value\n", []]] as $case => [$stdout, $events]) {
            $this->assertSame([0, $stdout, '', $events], array_slice($rows[$case], 0, 4), $case);
        }
        $this->assertSame([1, '', "Invalid --lighttpd-min-threads value; expected a non-negative integer\n", []],
            array_slice($rows['invalid-floor'], 0, 4));
    }

    public function testCliRunsPreparedSettingsBeforeLimitsAndServiceConfiguration(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/userConfig.php', [
            'pmssUserConfigPrepare($argv ?? null)', 'pmssUserConfigLimitsApply($user, $expectedHome)',
            'Creating rTorrent config', '$rtorrentConfig->writeConfig(', '$store->persist(',
            'updateRutorrentConfig(', 'userConfigureDeluge(', 'pmssQbittorrentWebUiAuthForce(',
            'pmssQbittorrentApplyUploadThrottle(', 'userApplyDiskQuota(', 'Restarting rTorrent',
            'Ensuring bash shell', 'pmssUserConfigApplyCgroupAndDocker(',
        ]);
    }
}
