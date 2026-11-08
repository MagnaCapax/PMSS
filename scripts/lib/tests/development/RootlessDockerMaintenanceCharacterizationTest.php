<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Lock config results and maintenance effects through recording dependencies only. */
final class RootlessDockerMaintenanceCharacterizationTest extends TestCase
{
    public function testFacadesLoadEveryExistingPublicHelper(): void
    {
        require_once $this->pmssRepoPath('scripts/lib/update/users/docker.php');
        foreach ([
            'scripts/lib/update/users/docker.php' => ['pmssEnsureLingerAndDocker', 'pmssUserMaintenancePruneStaleLinger'],
            'scripts/lib/update/users/dockerCommands.php' => ['pmssRunAndLog', 'pmssUserMaintenanceUsernameAllowed'],
            'scripts/lib/update/users/dockerInstall.php' => ['pmssUserDockerUnitExecBinary', 'pmssEnsureRootlessDockerInstalled'],
            'scripts/lib/update/users/dockerDependencies.php' => ['pmssUserDockerFuseOverlayfsState', 'pmssEnsureDockerDependencies'],
            'scripts/lib/user/rootlessDockerConfig.php' => ['pmssUserRootlessDockerConfigConverge', 'pmssUserRootlessDockerConfigWrite', 'pmssUserDockerLingerEnsureCommands'],
            'scripts/lib/user/rootlessDockerPolicy.php' => ['pmssUserRootlessDockerConfigRoundTrips', 'pmssUserRootlessDockerIsJsonObject', 'pmssUserRootlessDockerBridgeMtuSupported', 'pmssUserRootlessDockerBridgeMtuApply'],
            'scripts/lib/user/rootlessDockerStore.php' => ['pmssUserRootlessDockerDataRoot', 'pmssUserRootlessDockerStoreExists'],
        ] as $path => $functions) {
            foreach ($functions as $function) {
                $this->assertSame(realpath($this->pmssRepoPath($path)), (new \ReflectionFunction($function))->getFileName(), $function);
            }
        }
    }

    public function testConfigResultsAndPublicationBytes(): void
    {
        $script = <<<'PHP'
namespace RootlessDockerConfigCharacterization;
function pmssEnsureUserHomeDir(...$args) { $GLOBALS['effects'][] = ['directory', $args[2], $args[3], $args[5]]; return true; }
function pmssLockFilePathIsSafe($path) { return $GLOBALS['case']['safe'] ?? true; }
function is_file($path) { return $GLOBALS['case']['exists']; }
function file_get_contents($path) { $GLOBALS['effects'][] = ['read', $path]; return $GLOBALS['case']['raw']; }
function posix_geteuid() { return 0; }
function posix_getpwuid($uid) { return ['gid' => 1235]; }
function pmssAccountHomeIdentity($path) { return ['/srv/pmss-fixture', 'fixtur01', 1234]; }
function pmssJsonEncodePretty($data) { return !empty($GLOBALS['case']['encodeFailure']) ? null : \pmssJsonEncodePretty($data); }
function pmssReplaceAccountFile(...$args) { $GLOBALS['effects'][] = ['write', $args]; return empty($GLOBALS['case']['writeFailure']); }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/rootlessDockerConfig.php',
            'RootlessDockerConfigCharacterization', ['rootlessDockerPolicy.php']);
        $script .= <<<'PHP'
$results = [];
foreach (json_decode(getenv('PMSS_TEST_DOCKER_CASES'), true) as $case) {
    $GLOBALS['case'] = $case;
    $GLOBALS['effects'] = [];
    $result = pmssUserRootlessDockerConfigConverge('fixtur01', '/srv/pmss-fixture', 1234, 1235, $case['policy']);
    $results[] = [$result, $GLOBALS['effects']];
}
echo json_encode($results);
PHP;
        $exec = ['exec-opts' => ['native.cgroupdriver=cgroupfs']];
        // These expectations describe the original facade's flags, order and bytes.
        $cases = [
            [false, false, [], [], null],
            [false, false, ['bridge_mtu' => 65520], [], null],
            [false, false, ['create_when_missing' => true], ['changed' => true, 'created' => true], $exec],
            [true, '{broken', [], ['ok' => false, 'reason' => 'invalid_json'], null],
            [true, '{broken', ['invalid_json_as_empty' => true], ['changed' => true], $exec],
            [true, '{"storage-driver":"custom"}', ['storage_driver' => 'fuse-overlayfs'], ['changed' => true], ['storage-driver' => 'custom'] + $exec],
            [true, '{"storage-driver":"custom"}', ['storage_driver' => 'fuse-overlayfs', 'preserve_custom_storage_driver' => null],
                ['changed' => true, 'configured_storage_driver' => true], ['storage-driver' => 'fuse-overlayfs'] + $exec],
            [true, '{"storage-driver":"fuse-overlayfs"}', ['remove_pmss_storage_driver' => true, 'storage_driver' => 'custom'],
                ['changed' => true, 'removed_storage_driver' => true], $exec],
            [true, '{"features":{"keep":1}}', ['disable_containerd_snapshotter' => true, 'bridge_mtu' => 65520],
                ['changed' => true, 'disabled_containerd_snapshotter' => true, 'configured_bridge_mtu' => true],
                ['features' => ['keep' => 1, 'containerd-snapshotter' => false],
                    'default-network-opts' => ['bridge' => ['com.docker.network.driver.mtu' => '65520']]] + $exec],
            [true, '{"log-opts":{}}', ['disable_containerd_snapshotter' => true, 'bridge_mtu' => 65520], ['ok' => false, 'reason' => 'lossy_json'], null],
            [true, '{"log-opts":{},"exec-opts":["native.cgroupdriver=cgroupfs"]}', [], [], null],
            [true, '{"exec-opts":["native.cgroupdriver=cgroupfs","native.cgroupdriver=cgroupfs"]}', [], [], null],
            [true, false, [], ['ok' => false, 'reason' => 'read_failed'], null],
            [true, '{}', [], ['ok' => false, 'reason' => 'unsafe_config_file'], null, ['safe' => false]],
            [true, '{}', ['disable_containerd_snapshotter' => true],
                ['ok' => false, 'reason' => 'encode_failed', 'disabled_containerd_snapshotter' => true], null, ['encodeFailure' => true]],
            [true, '{}', ['disable_containerd_snapshotter' => true],
                ['ok' => false, 'reason' => 'write_failed', 'disabled_containerd_snapshotter' => true],
                ['features' => ['containerd-snapshotter' => false]] + $exec, ['writeFailure' => true]],
        ];
        $input = [];
        foreach ($cases as $case) $input[] = ['exists' => $case[0], 'raw' => $case[1], 'policy' => $case[2]] + ($case[5] ?? []);
        $actual = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_DOCKER_CASES' => json_encode($input)]);
        $path = '/srv/pmss-fixture/.config/docker/daemon.json';
        foreach ($cases as $index => $case) {
            $expected = array_replace([
                'ok' => true, 'changed' => false, 'created' => false,
                'removed_storage_driver' => false, 'configured_storage_driver' => false,
                'disabled_containerd_snapshotter' => false, 'configured_bridge_mtu' => false,
                'reason' => '', 'path' => $path,
            ], $case[3]);
            $effects = [['directory', '.config/docker', 0700, 0700]];
            if ($case[0] && ($case[5]['safe'] ?? true)) $effects[] = ['read', $path];
            if ($case[4] !== null) {
                $effects[] = ['write', ['fixtur01', '/srv/pmss-fixture', $path,
                    json_encode($case[4], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0600, true]];
            }
            $this->assertSame([$expected, $effects], $actual[$index], 'config case '.$index);
        }
    }

    public function testCommandLoggingKeepsStreamOrderAndFailureResults(): void
    {
        $script = <<<'PHP'
namespace RootlessDockerCommandCharacterization;
function pmssValidateUsername($user) { return $user === 'fixtur01'; }
function pmssLogSafeLabel($user) { return $user; }
function logMessage($message) { $GLOBALS['logs'][] = $message; }
function pmssUserLog($user, $message) { $GLOBALS['logs'][] = $message; }
function pmssBuildUserShellCommand($user, $command) { return 'account:'.$user.':'.$command; }
function pmssCommandCapture(...$args) { $GLOBALS['calls'][] = $args; return $GLOBALS['result']; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/users/dockerCommands.php', 'RootlessDockerCommandCharacterization');
        $script .= <<<'PHP'
$results = [];
foreach ([[0, "out\n", "err\n", true], [127, '', 'Failed to start process', false], [127, 'partial', '', false], [9, '', '', false]] as $case) {
    $GLOBALS['result'] = ['rc' => $case[0], 'stdout' => $case[1], 'stderr' => $case[2]];
    $GLOBALS['calls'] = $GLOBALS['logs'] = [];
    $rc = pmssRunAndLog('fixtur01', 'fixture', 'fixture-command', $case[3]);
    $results[] = [$rc, $GLOBALS['calls'], $GLOBALS['logs']];
}
$GLOBALS['calls'] = $GLOBALS['logs'] = [];
$results[] = [pmssRunAndLog('bad;name', 'fixture', 'fixture-command'), $GLOBALS['calls'], $GLOBALS['logs']];
echo json_encode($results);
PHP;
        $call = ['fixture-command', 0, true, 'Failed to start process', 127];
        $asUser = $call;
        $asUser[0] = 'account:fixtur01:fixture-command';
        $this->assertSame([
            [0, [$asUser], ['[CMD] fixture: fixture-command', 'out', 'err', '[RC] fixture -> 0']],
            [127, [$call], ['[CMD] fixture: fixture-command', '[ERR] Failed to start process for: fixture']],
            [127, [$call], ['[CMD] fixture: fixture-command', 'partial', '[RC] fixture -> 127']],
            [9, [$call], ['[CMD] fixture: fixture-command', '[RC] fixture -> 9']],
            [127, [], ['[WARN] pmssRunAndLog refused invalid username: bad;name']],
        ], $this->pmssRunInlinePhpJson($script));
    }

    public function testMaintenancePreservesStartRestartAndLingerOnlyOrder(): void
    {
        $script = <<<'PHP'
namespace RootlessDockerMaintenanceCharacterization;
class UserConfigStore {}
function pmssUserMaintenanceUsernameAllowed($user, $context) { return true; }
function is_dir($path) { return false; }
function pmssUserDockerEnabled(...$args) { return $GLOBALS['enabled']; }
function pmssUserLingerEnabled(...$args) { return $GLOBALS['linger']; }
function pmssSystemdRuntimeAvailable() { return true; }
function shell_exec($command) { return '1234'; }
function gethostname() { return 'fixture-host'; }
function pmssStreamIsTty(...$args) { return false; }
function pmssUserLog(...$args) {}
function logMessage(...$args) {}
function pmssRunAndLog($user, $label, $command, $asUser = false) { $GLOBALS['calls'][] = [$label, $command, $asUser]; return 0; }
function pmssEnsureRootlessDockerInstalled($user) { $GLOBALS['calls'][] = ['install', $user]; }
function pmssEnsureDockerDependencies($user) { $GLOBALS['calls'][] = ['dependencies', $user]; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/users/docker.php', 'RootlessDockerMaintenanceCharacterization');
        $script .= <<<'PHP'
$results = [];
foreach ([[true, true, '0'], [true, true, '1'], [false, true, '0'], [false, false, '0']] as $case) {
    [$GLOBALS['enabled'], $GLOBALS['linger'], $changed] = $case;
    putenv('PMSS_DOCKER_PACKAGES_CHANGED='.$changed);
    $GLOBALS['calls'] = [];
    pmssEnsureLingerAndDocker('fixtur01');
    $results[] = $GLOBALS['calls'];
}
echo json_encode($results);
PHP;
        $linger = ['loginctl enable-linger', "loginctl enable-linger 'fixtur01'", false];
        $stop = ['userDocker stop (disabled)', "php /scripts/util/userDocker.php 'fixtur01' stop", false];
        $prefix = [$linger, ['install', 'fixtur01'],
            ['systemctl status user@.service (pre)', 'systemctl --no-pager -l status user@1234.service || true', false],
            ['systemctl start user@.service', 'systemctl start user@1234.service', false],
            ['systemctl status user@.service (post)', 'systemctl --no-pager -l status user@1234.service || true', false],
            ['dependencies', 'fixtur01']];
        $status = ['userDocker status', "php /scripts/util/userDocker.php 'fixtur01' status", false];
        $this->assertSame([
            array_merge($prefix, [['userDocker start', "php /scripts/util/userDocker.php 'fixtur01' start", false], $status]),
            array_merge($prefix, [['userDocker restart', "php /scripts/util/userDocker.php 'fixtur01' restart", false], $status]),
            [$stop, $linger], [$stop],
        ], $this->pmssRunInlinePhpJson($script));
    }

    public function testFuseProbeCachesFirstExecutableIncludingItsFailure(): void
    {
        $script = <<<'PHP'
namespace RootlessDockerFuseCharacterization;
function is_file($path) { return getenv('PMSS_TEST_FUSE_MODE') !== 'missing'; }
function is_executable($path) { return getenv('PMSS_TEST_FUSE_MODE') !== 'second' || strpos($path, '/usr/local/') === 0; }
function exec($command, &$out, &$rc) { $GLOBALS['calls'][] = $command; $rc = getenv('PMSS_TEST_FUSE_MODE') === 'failed' ? 1 : 0; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/users/dockerDependencies.php', 'RootlessDockerFuseCharacterization');
        $script .= '$GLOBALS["calls"] = []; echo json_encode([pmssUserDockerFuseOverlayfsState(), pmssUserDockerFuseOverlayfsState(), $GLOBALS["calls"]]);';
        foreach (['missing' => [false, null], 'failed' => [false, '/usr/bin/fuse-overlayfs'], 'second' => [true, '/usr/local/bin/fuse-overlayfs']] as $mode => $case) {
            $state = ['available' => $case[0], 'path' => $case[1]];
            $calls = $case[1] === null ? [] : [escapeshellarg($case[1]).' --version >/dev/null 2>&1'];
            $this->assertSame([$state, $state, $calls], $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_FUSE_MODE' => $mode]), $mode);
        }
    }
}
