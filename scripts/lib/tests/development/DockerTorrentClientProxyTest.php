<?php
/** Hermetic Docker client identity and proxy routing fixtures. */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/dockerInstallLsio.php';
require_once dirname(__DIR__, 2).'/lighttpd/proxyFragments.php';
require_once dirname(__DIR__, 2).'/lighttpd/dockerClientProxy.php';

final class DockerTorrentClientProxyTest extends TestCase
{
    private function inspectFixture(string $app, string $port): array
    {
        return [
            'Name' => '/'.$app,
            'State' => ['Running' => true],
            'Config' => ['Labels' => ['pmss.client' => $app]],
            'NetworkSettings' => ['Ports' => [$port.'/tcp' => [['HostIp' => '127.0.0.1', 'HostPort' => $port]]]],
        ];
    }

    public function testTorrentClientCatalogAndRunCommandUseLoopbackAndLabel(): void
    {
        $spec = \pmssDockerInstallLsioAppSpec('transmission', '/srv/customer', false);
        $spec['peerPort'] = 23002;
        $this->assertSame('127.0.0.1', $spec['bindHost']);
        $this->assertSame('9091', $spec['containerPort']);
        $this->assertSame('/srv/customer/docker/transmission/pmss-credentials.env', $spec['credentialFile']);
        $this->assertSame(['/srv/customer/docker/transmission/config', '/srv/customer/downloads', '/srv/customer/watch'], $spec['mkdirPaths']);
        $command = \pmssDockerInstallLsioDockerRunCommand('transmission', '23001', 'UTC', $spec);
        $this->assertSame('pmss.client=transmission', $command[array_search('--label', $command, true) + 1]);
        $this->assertSame('127.0.0.1:23001:9091', $command[array_search('-p', $command, true) + 1]);
        $this->assertSame('lscr.io/linuxserver/transmission:4.1.3-r0-ls365@sha256:55aaeedf9fc354d34136387e15ee0ba4679096f20398dd2875867e5cd5cf8be5', end($command));
        $this->assertSame($spec['credentialFile'], $command[array_search('--env-file', $command, true) + 1]);
        $this->assertFalse(in_array('USER=', $command, true));
        $this->assertFalse(in_array('PASS=', $command, true));
    }

    public function testInspectRequiresRunningLabelledLoopbackClient(): void
    {
        $fixture = $this->inspectFixture('transmission', '9091');
        $this->assertSame(9091, \pmssLighttpdDockerClientPortFromInspect($fixture, 'transmission'));
        $this->assertSame(9091, \pmssLighttpdDockerClientPort('customer', 'transmission',
            static function () use ($fixture): string { return json_encode($fixture); }));
        $stopped = $fixture;
        $stopped['State']['Running'] = false;
        $this->assertSame(null, \pmssLighttpdDockerClientPortFromInspect($stopped, 'transmission'));
        $wrongLabel = $fixture;
        $wrongLabel['Config']['Labels']['pmss.client'] = 'other';
        $this->assertSame(null, \pmssLighttpdDockerClientPortFromInspect($wrongLabel, 'transmission'));
        $wildcard = $fixture;
        $wildcard['NetworkSettings']['Ports']['9091/tcp'][0]['HostIp'] = '0.0.0.0';
        $this->assertSame(null, \pmssLighttpdDockerClientPortFromInspect($wildcard, 'transmission'));
        $wrongName = $fixture;
        $wrongName['Name'] = '/other';
        $this->assertSame(null, \pmssLighttpdDockerClientPortFromInspect($wrongName, 'transmission'));
        $this->assertSame(null, \pmssLighttpdDockerClientPort('bad/user', 'transmission', static function (): string { return '{}'; }));
    }

    public function testDockerFragmentsUsePanelGateAndClientPaths(): void
    {
        $transmission = \pmssLighttpdManagedProxyFragment('transmission', 'customer', 9091);
        $this->assertStringNotContainsString('auth.require = ()', $transmission);
        $this->assertStringContainsAllStrings(['"Authorization" => ""', '"host" => "127.0.0.1"',
            '^/user-customer/transmission/', '"/user-customer/transmission/"  => "/transmission/"',
            '"port" => 9091'], $transmission);
    }

    public function testQbittorrentInspectIgnoresPeerPublishesButRejectsUnsafeWebui(): void
    {
        $fixture = $this->inspectFixture('qbittorrent', '8080');
        $fixture['NetworkSettings']['Ports']['8080/tcp'][0]['HostPort'] = '23001';
        foreach (['tcp', 'udp'] as $protocol) {
            $fixture['NetworkSettings']['Ports']['23002/'.$protocol] = [['HostIp' => '0.0.0.0', 'HostPort' => '23002']];
        }
        $this->assertSame(23001, \pmssLighttpdDockerClientPortFromInspect($fixture, 'qbittorrent'));
        $this->assertSame(23001, \pmssLighttpdDockerClientPort('customer', 'qbittorrent',
            static function () use ($fixture): string { return json_encode($fixture); }));
        foreach (['wildcard', 'label', 'stopped', 'name', 'multiple'] as $case) {
            $invalid = $fixture;
            if ($case === 'wildcard') $invalid['NetworkSettings']['Ports']['8080/tcp'][0]['HostIp'] = '0.0.0.0';
            if ($case === 'label') $invalid['Config']['Labels']['pmss.client'] = 'transmission';
            if ($case === 'stopped') $invalid['State']['Running'] = false;
            if ($case === 'name') $invalid['Name'] = '/other';
            if ($case === 'multiple') $invalid['NetworkSettings']['Ports']['8080/tcp'][] = ['HostIp' => '127.0.0.1', 'HostPort' => '23003'];
            $this->assertSame(null, \pmssLighttpdDockerClientPortFromInspect($invalid, 'qbittorrent'), $case);
        }
        $fragment = \pmssLighttpdManagedProxyFragment('qbittorrent', 'customer', 23001);
        $this->assertStringContainsAllStrings(['^/user-customer/qbittorrent/', '"port" => 23001'], $fragment);
    }
}
