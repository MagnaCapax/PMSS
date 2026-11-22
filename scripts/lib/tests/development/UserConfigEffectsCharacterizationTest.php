<?php
/** Characterize reconfiguration limits and service commands without filesystem or process actions. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigRuntime.php';

class UserConfigEffectsCharacterizationTest extends TestCase
{
    public function testServiceCommandsAndNonfatalCgroupFailureKeepTheirOrder(): void
    {
        $script = 'namespace UserConfigServicesFixture; require_once '
            .var_export(dirname(__DIR__, 2).'/user/userConfigCli.php', true).';';
        $script .= <<<'PHP'
function pmssUserDockerEnabled($user, $store) { $GLOBALS['events'][] = 'docker'; return $GLOBALS['docker']; }
function pmssUserLingerEnabled($user, $store) { $GLOBALS['events'][] = 'linger'; return $GLOBALS['linger']; }
function runStep($label, $command) {
    $GLOBALS['events'][] = [$label, $command]; return $label === 'Configuring cgroups' ? $GLOBALS['rc'] : 0;
}
function pmssUserConfigCgroupApplyFailureLog($user, $rc) { $GLOBALS['events'][] = ['failure', $user, $rc]; }
function pmssLogStatus($status, $message) { $GLOBALS['events'][] = [$status, $message]; }
PHP;
        $script .= \pmssTestingFunctionExport('pmssUserConfigApplyCgroupAndDocker', 'UserConfigServicesFixture');
        $script .= <<<'PHP'
$rows = [];
foreach ([[false, false, 0, null], [false, true, 0, 0], [true, false, 0, 'INFINITY'],
    [true, true, 7, 125], [false, false, 7, '']] as [$GLOBALS['docker'], $GLOBALS['linger'], $GLOBALS['rc'], $quota]) {
    $GLOBALS['events'] = []; $user = ['name' => 'fixtur01', 'memory' => 512];
    if ($quota !== null) $user['cpuQuotaPercent'] = $quota;
    ob_start(); pmssUserConfigApplyCgroupAndDocker($user, null);
    $rows[] = [ob_get_clean(), $GLOBALS['events']];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        $baseCommand = "php '/scripts/util/userConfigCgroup.php' 'fixtur01' '--apply' '--memory-high=512'";
        foreach ([[false, false, 0, null, ''], [false, true, 0, '0', "Applying CPU quota: 0%\n"],
            [true, false, 0, 'INFINITY', "Applying CPU quota: infinity\n"],
            [true, true, 7, '125', "Applying CPU quota: 125%\n"], [false, false, 7, null, '']] as $i => $case) {
            [$docker, $linger, $rc, $quota, $stdout] = $case;
            $events = [['Configuring cgroups', $baseCommand.($quota === null ? '' : " '--cpu-quota-percent=".$quota."'")]];
            if ($rc !== 0) $events[] = ['failure', 'fixtur01', 7];
            $events = array_merge($events, ['docker', 'linger']);
            if ($docker || $linger) $events[] = ['Enabling linger for user', "loginctl enable-linger 'fixtur01'"];
            if ($docker) {
                $events[] = ['Installing systemd-container tools', 'apt-get install -y systemd-container'];
                $events[] = ['Configuring rootless Docker', "su 'fixtur01' -c ".escapeshellarg(
                    'export XDG_RUNTIME_DIR=/run/user/$(id -u); export PATH="$PATH:/usr/sbin:/sbin"; /usr/bin/dockerd-rootless-setuptool.sh install')];
            } else {
                $events[] = ['SKIP', 'Rootless Docker disabled by config for fixtur01'];
            }
            $this->assertSame([$stdout, $events], $rows[$i], (string) $i);
        }
    }

    public function testLimitsRemainFailSoftAndDoNotWriteAfterPreparationFailure(): void
    {
        $script = <<<'PHP'
namespace UserConfigLimitsFixture;
function fwrite($handle, $message) { $GLOBALS['stderr'] .= $message; return strlen($message); }
function pmssAddUserBonusQuotaPersist($user, $home) { $GLOBALS['events'][] = 'bonus'; return $GLOBALS['case'] !== 'bonus'; }
function userApplyTrafficLimit($user) { $GLOBALS['events'][] = 'traffic'; }
function pmssIopsLimitTargetModes($user, $root) {
    $GLOBALS['events'][] = ['targets', $user, $root];
    return $GLOBALS['case'] === 'targets' ? [] : ['/fixture/runtime/limit' => 0600];
}
function pmssIntegerSettingStorageDirEnsure($dir, $mode) {
    $GLOBALS['events'][] = ['directory', $dir, $mode]; return $GLOBALS['case'] !== 'directory';
}
function pmssIntegerSettingTargetModesPersist($targets, $limit, &$error, $invalid) {
    $GLOBALS['events'][] = ['persist', $limit, $invalid];
    if ($GLOBALS['case'] === 'throw') throw new \RuntimeException('fixture write');
    if ($GLOBALS['case'] === 'persist') { $error = 'fixture failure'; return false; }
    return true;
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/userConfigLimits.php', 'UserConfigLimitsFixture');
        $script .= <<<'PHP'
$rows = [];
foreach (['absent', 'null', 'zero', 'bonus', 'targets', 'directory', 'persist', 'throw'] as $GLOBALS['case']) {
    $GLOBALS['events'] = []; $GLOBALS['stderr'] = ''; $error = null; $user = ['name' => 'fixtur01'];
    if ($GLOBALS['case'] !== 'absent') $user['iopsLimit'] = $GLOBALS['case'] === 'null' ? null : 0;
    try { pmssUserConfigLimitsApply($user, '/fixture/home/fixtur01'); }
    catch (\RuntimeException $fault) { $error = $fault->getMessage(); }
    $rows[$GLOBALS['case']] = [$GLOBALS['events'], $GLOBALS['stderr'], $error];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        foreach (['absent', 'null'] as $case) $this->assertSame([['bonus', 'traffic'], '', null], $rows[$case]);
        $targets = ['targets', 'fixtur01', '/fixture/home'];
        $directory = ['directory', '/fixture/runtime', 0700];
        $persist = ['persist', 0, 'invalid operations value'];
        $complete = ['bonus', 'traffic', $targets, $directory, $persist];
        $this->pmssAssertArraySubsetSame(['zero' => [$complete, '', null],
            'bonus' => [$complete, "Warning: failed to persist bonus quota display copy for fixtur01\n", null]], $rows);
        foreach (['targets' => 3, 'directory' => 4, 'persist' => 5] as $case => $length) {
            $reason = $case === 'persist' ? 'fixture failure' : 'unable to prepare runtime targets';
            $this->assertSame([array_slice($complete, 0, $length),
                "Warning: failed to persist IOPS limit for fixtur01: ".$reason."\n", null], $rows[$case]);
        }
        $this->assertSame([$complete, '', 'fixture write'], $rows['throw']);
    }

    public function testDerivedPortPersistenceKeepsThePositiveChangedGate(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/userConfig.php');
        $start = strpos($source, '$port = (int)');
        $end = strpos($source, 'echo "Changing ruTorrent config', $start);
        $this->assertTrue($start !== false && $end !== false);
        $script = <<<'PHP'
namespace UserConfigPortFixture;
function fwrite($handle, $message) { $GLOBALS['stderr'] .= $message; return strlen($message); }
class Store {
    function persist($user, $payload) { $GLOBALS['events'][] = [$user, $payload]; return $GLOBALS['success']; }
}
PHP;
        $script .= '$code = '.var_export('namespace UserConfigPortFixture;'.substr($source, $start, $end - $start), true).';';
        $script .= <<<'PHP'
$rows = []; $store = new Store(); $user = ['name' => 'fixtur01'];
foreach ([[0, 5000, true], [-1, 5000, true], [5000, '5000', true], [5001, 5000, true],
    [5001, 5000, false], [5001, null, true]] as [$next, $old, $GLOBALS['success']]) {
    $GLOBALS['events'] = []; $GLOBALS['stderr'] = ''; $payload = $old === null ? [] : ['rtorrentPort' => $old];
    $configuration = ['config' => ['scgiPort' => $next]]; eval($code);
    $rows[] = [$payload, $GLOBALS['events'], $GLOBALS['stderr']];
}
echo json_encode($rows);
PHP;
        $rows = $this->pmssRunInlinePhpJson($script);
        foreach ([0 => 5000, 1 => 5000, 2 => '5000'] as $i => $port) {
            $this->assertSame([['rtorrentPort' => $port], [], ''], $rows[$i]);
        }
        foreach ([3, 4, 5] as $i) {
            $payload = ['rtorrentPort' => 5001];
            $warning = $i === 4 ? "Warning: failed to persist rTorrent ports for fixtur01\n" : '';
            $this->assertSame([$payload, [['fixtur01', $payload]], $warning], $rows[$i]);
        }
    }
}
