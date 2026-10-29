<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/rootlessDockerConfig.php';

/** Bridge MTU convergence for rootless Docker daemon.json (fill missing keys only). */
class RootlessDockerBridgeMtuTest extends TestCase
{
    // Missing the cgroupfs exec-opt, so every converge has something to write.
    private const LOSSY_DAEMON_JSON = '{"log-opts": {}}';

    public function testCgroupfsDaemonConfigLogsLossyJsonAndLeavesFileUnchanged(): void
    {
        $script = <<<'PHP'
namespace RootlessDockerStartFixture;
function pmssCgroupModeWithDefault($default) { return 'v2'; }
function pmssUserLog($user, $message) { $GLOBALS['logs'][] = $message; }
PHP;
        $script .= "\nrequire_once ".var_export($this->pmssRepoPath('scripts/lib/user/rootlessDockerConfig.php'), true).";\n";
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/user/docker/start.php', 'RootlessDockerStartFixture');
        $script .= <<<'PHP'
$GLOBALS['logs'] = [];
userDockerEnsureCgroupfsDaemonConfig('alice', getenv('PMSS_TEST_HOME'), getmyuid(), getmygid());
echo json_encode($GLOBALS['logs']);
PHP;

        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        $path = $this->writeRawDaemonConfig($home, self::LOSSY_DAEMON_JSON);
        $logs = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_HOME' => $home]);
        $this->assertSame(['[WARN] userDocker: daemon.json has an empty or numeric-key object PMSS cannot rewrite safely; leaving it unchanged'], $logs);
        $this->assertSame(self::LOSSY_DAEMON_JSON, file_get_contents($path));

        file_put_contents($path, "{}\n");
        $logs = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_HOME' => $home]);
        $this->assertSame(['userDocker: updated ~/.config/docker/daemon.json for cgroup v2 rootless Docker'], $logs);
        $this->assertSame(['native.cgroupdriver=cgroupfs'], $this->readDaemonConfig($home)['exec-opts']);
    }

    public function testDockerDependenciesGateMtuOnEngineAndLogLossyJson(): void
    {
        $script = <<<'PHP'
namespace RootlessDockerDependenciesFixture;
function pmssUserLog($user, $message) { $GLOBALS['logs'][] = $message; }
function pmssDistroVersionFromEnv() { return 12; }
function pmssUserAccountLookup($user) { return ['uid' => getmyuid(), 'gid' => getmygid(), 'dir' => getenv('PMSS_TEST_HOME')]; }
function pmssPackageVersion($package) { return $package === 'docker-ce' ? getenv('PMSS_TEST_DOCKER_CE') : ''; }
function is_executable($path) { return false; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/update/users/docker.php', 'RootlessDockerDependenciesFixture');
        $script .= <<<'PHP'
$GLOBALS['logs'] = [];
pmssEnsureDockerDependencies('alice');
echo json_encode(array_values(array_filter($GLOBALS['logs'], static function ($line) {
    return strpos($line, 'subuid') === false && strpos($line, 'subgid') === false && strpos($line, 'fuse-overlayfs') === false;
})));
PHP;

        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        $path = $this->writeRawDaemonConfig($home, self::LOSSY_DAEMON_JSON);
        $env = ['PMSS_TEST_HOME' => $home, 'PMSS_TEST_DOCKER_CE' => '5:26.1.4-1~debian.10~buster'];
        $this->assertSame(['[WARN] daemon.json has an empty or numeric-key object PMSS cannot rewrite safely; left unchanged'],
            $this->pmssRunInlinePhpJson($script, $env));
        $this->assertSame(self::LOSSY_DAEMON_JSON, file_get_contents($path));

        $complete = '{"exec-opts": ["native.cgroupdriver=cgroupfs"]}';
        foreach (['5:23.0.6-1~debian.12~bookworm' => false, '' => false, '5:24.0.0-1~debian.12~bookworm' => true, '29.0.1' => true] as $version => $applies) {
            file_put_contents($path, $complete);
            $logs = $this->pmssRunInlinePhpJson($script, ['PMSS_TEST_DOCKER_CE' => $version] + $env);
            $this->assertSame($applies, isset($this->readDaemonConfig($home)['default-network-opts']), 'docker-ce '.$version);
            $this->assertSame($applies ? ['[INFO] Configured Docker MTU 65520 for new networks (next daemon start; existing networks and the default bridge keep theirs)'] : [],
                $logs, 'docker-ce '.$version);
            if (!$applies) {
                $this->assertSame($complete, file_get_contents($path), 'docker-ce '.$version);
            }
        }
    }

    public function testBridgeMtuSupportedFromEngine24(): void
    {
        foreach (['5:24.0.0-1~debian.12~bookworm', '5:26.1.4-1~debian.10~buster', '29.0.1-1', '24'] as $version) {
            $this->assertTrue(\pmssUserRootlessDockerBridgeMtuSupported($version), $version);
        }
        foreach (['5:23.0.6-1~debian.12~bookworm', '5:20.10.24~3-0~debian-bullseye', '19.03.15', '', 'unknown'] as $version) {
            $this->assertFalse(\pmssUserRootlessDockerBridgeMtuSupported($version), $version);
        }
    }

    public function testWireguardInstallerUsesA1500NetworkAndStopsOnFailures(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-wireguard-installer-');
        @mkdir($dir.'/bin', 0755, true);
        @mkdir($dir.'/home', 0755, true);
        // Stub docker: PMSS_TEST_NETWORK is missing, an MTU, create-fails or inspect-fails.
        $stub = <<<'SH'
#!/bin/bash
echo "$*" >> "$PMSS_TEST_CALLS"
case "$1 $2" in
"network inspect")
	[ "$PMSS_TEST_NETWORK" = missing ] || [ "$PMSS_TEST_NETWORK" = create-fails ] && exit 1
	if [ "$3" = -f ]; then
		[ "$PMSS_TEST_NETWORK" = inspect-fails ] && exit 1
		echo "$PMSS_TEST_NETWORK"
	fi
	exit 0 ;;
"network create")
	[ "$PMSS_TEST_NETWORK" = create-fails ] && exit 1
	exit 0 ;;
esac
exit 0
SH;
        $this->pmssWriteExecutableFile($dir.'/bin/docker', $stub);
        $script = $this->pmssRepoPath('etc/skel/bin/docker-install-wireguard.sh');
        $create = 'network create --opt com.docker.network.driver.mtu=1500 wireguard';
        foreach (['missing' => [0, true, true], '1500' => [0, false, true], '65520' => [1, false, false],
            'create-fails' => [1, true, false], 'inspect-fails' => [1, false, false]] as $network => [$rc, $creates, $runs]) {
            $calls = $dir.'/calls-'.$network;
            $result = $this->pmssExecShellCommand(sprintf('HOME=%s PATH=%s:"$PATH" PMSS_TEST_NETWORK=%s PMSS_TEST_CALLS=%s bash %s 30000',
                escapeshellarg($dir.'/home'), escapeshellarg($dir.'/bin'), escapeshellarg($network), escapeshellarg($calls), escapeshellarg($script)));
            $lines = file($calls, FILE_IGNORE_NEW_LINES) ?: [];
            $this->assertSame($rc, $result['rc'], $network.': '.$result['output']);
            $this->assertSame($creates, in_array($create, $lines, true), $network);
            $run = array_values(preg_grep('/^run /', $lines));
            $this->assertSame($runs, $run !== [], $network);
            if ($runs) {
                $this->assertTrue(strpos($run[0], ' --network wireguard ') !== false, $run[0]);
            }
        }
    }

    public function testApplyFillsNetworkOptionButNotDefaultBridge(): void
    {
        $this->assertSame(65520, PMSS_ROOTLESS_DOCKER_BRIDGE_MTU);
        $data = [];
        $this->assertTrue(\pmssUserRootlessDockerBridgeMtuApply($data, 65520));
        $this->assertSame(['default-network-opts' => ['bridge' => ['com.docker.network.driver.mtu' => '65520']]], $data);
    }

    public function testApplyIsIdempotent(): void
    {
        $data = [];
        \pmssUserRootlessDockerBridgeMtuApply($data, 65520);
        $before = $data;
        $this->assertFalse(\pmssUserRootlessDockerBridgeMtuApply($data, 65520));
        $this->assertSame($before, $data);
    }

    public function testApplyKeepsAccountValuesAndSiblingKeys(): void
    {
        $data = [
            'mtu' => 1500,
            'default-network-opts' => ['bridge' => ['com.docker.network.driver.mtu' => '1400', 'other' => 'x'], 'macvlan' => ['k' => 'v']],
        ];
        $before = $data;
        $this->assertFalse(\pmssUserRootlessDockerBridgeMtuApply($data, 65520));
        $this->assertSame($before, $data);
    }

    public function testApplyKeepsSiblingOptions(): void
    {
        $data = ['mtu' => 9000];
        $this->assertTrue(\pmssUserRootlessDockerBridgeMtuApply($data, 65520));
        $this->assertSame(9000, $data['mtu']);
        $this->assertSame('65520', $data['default-network-opts']['bridge']['com.docker.network.driver.mtu']);

        $data = ['default-network-opts' => ['bridge' => ['com.docker.network.driver.mtu' => '1500']]];
        $this->assertFalse(\pmssUserRootlessDockerBridgeMtuApply($data, 65520));
        $this->assertTrue(!array_key_exists('mtu', $data));

        $data = ['default-network-opts' => ['macvlan' => ['k' => 'v']]];
        $this->assertTrue(\pmssUserRootlessDockerBridgeMtuApply($data, 65520));
        $this->assertSame(['k' => 'v'], $data['default-network-opts']['macvlan']);
        $this->assertSame('65520', $data['default-network-opts']['bridge']['com.docker.network.driver.mtu']);
    }

    public function testApplyLeavesNonObjectNetworkOptsAlone(): void
    {
        foreach (['bad', 42, null, true] as $invalid) {
            $data = ['mtu' => 1500, 'default-network-opts' => $invalid];
            $this->assertFalse(\pmssUserRootlessDockerBridgeMtuApply($data, 65520));
            $this->assertSame($invalid, $data['default-network-opts']);
            $data = ['mtu' => 1500, 'default-network-opts' => ['bridge' => $invalid]];
            $this->assertFalse(\pmssUserRootlessDockerBridgeMtuApply($data, 65520));
            $this->assertSame($invalid, $data['default-network-opts']['bridge']);
        }
    }

    public function testApplyLeavesJsonListsAlone(): void
    {
        foreach ([['default-network-opts' => ['x']], ['default-network-opts' => ['bridge' => ['x']]],
            ['default-network-opts' => ['bridge' => ['x', 'y']]]] as $data) {
            $before = $data;
            $this->assertFalse(\pmssUserRootlessDockerBridgeMtuApply($data, 65520), json_encode($before));
            $this->assertSame($before, $data);
        }

        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        $raw = '{"exec-opts": ["native.cgroupdriver=cgroupfs"], "default-network-opts": {"bridge": ["x"]}}';
        $path = $this->writeRawDaemonConfig($home, $raw);
        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, ['bridge_mtu' => 65520]);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => false, 'configured_bridge_mtu' => false], $result);
        $this->assertSame($raw, file_get_contents($path));
    }

    public function testApplyRejectsInvalidMtuValues(): void
    {
        foreach ([0, -1, 1279, 65536, '65520', 65520.0, null, false, []] as $invalid) {
            $data = [];
            $this->assertFalse(\pmssUserRootlessDockerBridgeMtuApply($data, $invalid));
            $this->assertSame([], $data);
        }
        $data = [];
        $this->assertTrue(\pmssUserRootlessDockerBridgeMtuApply($data, 1280));
        $data = [];
        $this->assertTrue(\pmssUserRootlessDockerBridgeMtuApply($data, 65535));
    }

    public function testConvergeWritesMtuWithCgroupfsAndReportsIt(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        $policy = ['bridge_mtu' => 65520, 'create_when_missing' => true];
        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, $policy);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => true, 'created' => true, 'configured_bridge_mtu' => true], $result);
        $payload = $this->readDaemonConfig($home);
        $this->assertTrue(!array_key_exists('mtu', $payload), 'The default bridge stays at 1500');
        $this->assertSame('65520', $payload['default-network-opts']['bridge']['com.docker.network.driver.mtu']);
        $this->assertSame(['native.cgroupdriver=cgroupfs'], $payload['exec-opts']);

        $again = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, $policy);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => false, 'configured_bridge_mtu' => false], $again);
    }

    public function testConvergeDoesNotCreateFileForMtuAlone(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, ['bridge_mtu' => 65520, 'create_when_missing' => false]);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => false, 'created' => false, 'configured_bridge_mtu' => false], $result);
        $this->assertFalse(file_exists($home.'/.config/docker/daemon.json'));
    }

    public function testConvergeRefillsDeletedKeysButKeepsExplicitOptOut(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        $this->writeDaemonConfig($home, ['exec-opts' => ['native.cgroupdriver=cgroupfs']]);
        \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, ['bridge_mtu' => 65520]);
        $this->writeDaemonConfig($home, ['exec-opts' => ['native.cgroupdriver=cgroupfs']]);
        $refilled = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, ['bridge_mtu' => 65520]);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'configured_bridge_mtu' => true], $refilled);
        $this->assertSame('65520', $this->readDaemonConfig($home)['default-network-opts']['bridge']['com.docker.network.driver.mtu'],
            'A deleted key is filled again');

        $optOut = ['exec-opts' => ['native.cgroupdriver=cgroupfs'],
            'default-network-opts' => ['bridge' => ['com.docker.network.driver.mtu' => '1500']]];
        $this->writeDaemonConfig($home, $optOut);
        $kept = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, ['bridge_mtu' => 65520]);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => false, 'configured_bridge_mtu' => false], $kept);
        $this->assertSame($optOut, $this->readDaemonConfig($home));
    }

    public function testConvergeLeavesFileWithEmptyObjectUntouched(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        @mkdir($home.'/.config/docker', 0755, true);
        $path = $home.'/.config/docker/daemon.json';
        foreach (['{"exec-opts": ["native.cgroupdriver=cgroupfs"], "log-opts": {}}',
            '{"exec-opts": ["native.cgroupdriver=cgroupfs"], "default-network-opts": {"overlay": {}}}',
            '{"exec-opts": ["native.cgroupdriver=cgroupfs"], "default-ulimits": {"0": {"Name": "x"}}}'] as $raw) {
            file_put_contents($path, $raw);
            $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, ['bridge_mtu' => 65520]);
            $this->pmssAssertArraySubsetSame(['ok' => false, 'changed' => false, 'reason' => 'lossy_json', 'configured_bridge_mtu' => false], $result);
            $this->assertSame($raw, file_get_contents($path), $raw);
        }

        $complete = '{"exec-opts": ["native.cgroupdriver=cgroupfs"], "log-opts": {},'
            .' "default-network-opts": {"bridge": {"com.docker.network.driver.mtu": "65520"}}}';
        file_put_contents($path, $complete);
        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, ['bridge_mtu' => 65520]);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => false], $result);
        $this->assertSame($complete, file_get_contents($path), 'Nothing to write, so no warning either');

        $this->assertTrue(\pmssUserRootlessDockerConfigRoundTrips('{"a": [], "b": {"c": 1.5}, "d": "\u00e9"}', ['a' => [], 'b' => ['c' => 1.5], 'd' => "\u{e9}"]));
    }

    public function testConvergeFillsTopLevelEmptyObject(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        @mkdir($home.'/.config/docker', 0755, true);
        foreach ([['create_when_missing' => true], ['bridge_mtu' => 65520]] as $policy) {
            file_put_contents($home.'/.config/docker/daemon.json', "{}\n");
            $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, $policy);
            $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => true], $result);
            $payload = $this->readDaemonConfig($home);
            $this->assertSame(['native.cgroupdriver=cgroupfs'], $payload['exec-opts']);
            $this->assertSame(isset($policy['bridge_mtu']), isset($payload['default-network-opts']));
        }
    }

    public function testConvergeKeepsTenantSettingsAndStorageDriver(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        $this->writeDaemonConfig($home, ['storage-driver' => 'overlay2', 'mtu' => 1500, 'dns' => ['192.0.2.53']]);
        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'bridge_mtu' => 65520,
            'preserve_custom_storage_driver' => true,
        ]);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => true, 'configured_bridge_mtu' => true], $result);
        $payload = $this->readDaemonConfig($home);
        $this->assertSame('overlay2', $payload['storage-driver']);
        $this->assertSame(1500, $payload['mtu']);
        $this->assertSame(['192.0.2.53'], $payload['dns']);
        $this->assertSame('65520', $payload['default-network-opts']['bridge']['com.docker.network.driver.mtu']);
    }

    public function testConvergeWithoutPolicyKeyLeavesMtuUnset(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, ['create_when_missing' => true]);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'configured_bridge_mtu' => false], $result);
        $payload = $this->readDaemonConfig($home);
        $this->assertTrue(!array_key_exists('mtu', $payload));
        $this->assertTrue(!array_key_exists('default-network-opts', $payload));
    }

    public function testConvergeRefusesInvalidJsonUnlessAllowed(): void
    {
        $home = $this->pmssMakeTempDir('pmss-rootless-docker-mtu-');
        @mkdir($home.'/.config/docker', 0755, true);
        file_put_contents($home.'/.config/docker/daemon.json', '{not json');
        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, ['bridge_mtu' => 65520]);
        $this->pmssAssertArraySubsetSame(['ok' => false, 'reason' => 'invalid_json', 'configured_bridge_mtu' => false], $result);
        $this->assertSame('{not json', file_get_contents($home.'/.config/docker/daemon.json'));
    }

    private function writeDaemonConfig(string $home, array $payload): void
    {
        @mkdir($home.'/.config/docker', 0755, true);
        file_put_contents($home.'/.config/docker/daemon.json', json_encode($payload));
    }

    private function writeRawDaemonConfig(string $home, string $raw): string
    {
        @mkdir($home.'/.config/docker', 0755, true);
        file_put_contents($home.'/.config/docker/daemon.json', $raw);
        return $home.'/.config/docker/daemon.json';
    }

    private function readDaemonConfig(string $home): array
    {
        $payload = json_decode((string) file_get_contents($home.'/.config/docker/daemon.json'), true);
        return is_array($payload) ? $payload : [];
    }
}
