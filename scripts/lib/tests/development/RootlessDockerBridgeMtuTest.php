<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/rootlessDockerConfig.php';

/** Bridge MTU convergence for rootless Docker daemon.json (fill missing keys only). */
class RootlessDockerBridgeMtuTest extends TestCase
{
    public function testBridgeMtuSourceContracts(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/update/users/docker.php' => ['required' => [
                "'bridge_mtu' => PMSS_ROOTLESS_DOCKER_BRIDGE_MTU,",
                "if (\$result['configured_bridge_mtu'])",
                "'lossy_json' => '[WARN] daemon.json has an empty or numeric-key object",
            ]],
            'scripts/lib/user/docker/start.php' => ['required' => [
                "'lossy_json' => '[WARN] userDocker: daemon.json has an empty or numeric-key object",
            ]],
            'etc/skel/bin/docker-install-wireguard.sh' => ['required' => [
                'docker network create --opt com.docker.network.driver.mtu=1500 "$NETWORK"',
                '--network "$NETWORK"',
                'if [ "$NETWORK_MTU" != "1500" ]; then',
            ]],
        ]);
        $this->assertSame(65520, PMSS_ROOTLESS_DOCKER_BRIDGE_MTU);
    }

    public function testApplyFillsNetworkOptionButNotDefaultBridge(): void
    {
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
        $this->writeDaemonConfig($home, ['storage-driver' => 'overlay2', 'mtu' => 1500, 'dns' => ['1.1.1.1']]);
        $result = \pmssUserRootlessDockerConfigConverge('alice', $home, 0, 0, [
            'bridge_mtu' => 65520,
            'preserve_custom_storage_driver' => true,
        ]);
        $this->pmssAssertArraySubsetSame(['ok' => true, 'changed' => true, 'configured_bridge_mtu' => true], $result);
        $payload = $this->readDaemonConfig($home);
        $this->assertSame('overlay2', $payload['storage-driver']);
        $this->assertSame(1500, $payload['mtu']);
        $this->assertSame(['1.1.1.1'], $payload['dns']);
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

    private function readDaemonConfig(string $home): array
    {
        $payload = json_decode((string) file_get_contents($home.'/.config/docker/daemon.json'), true);
        return is_array($payload) ? $payload : [];
    }
}
