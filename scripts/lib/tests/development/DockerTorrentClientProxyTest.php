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
        $this->assertSame('127.0.0.1', $spec['bindHost']);
        $this->assertSame('9091', $spec['containerPort']);
        $this->assertSame('/srv/customer/docker/transmission/pmss-credentials.env', $spec['credentialFile']);
        $this->assertSame(['/srv/customer/docker/transmission/config', '/srv/customer/downloads', '/srv/customer/watch'], $spec['mkdirPaths']);
        $command = \pmssDockerInstallLsioDockerRunCommand('transmission', '9091', 'UTC', $spec);
        $this->assertSame('pmss.client=transmission', $command[array_search('--label', $command, true) + 1]);
        $this->assertSame('127.0.0.1:9091:9091', $command[array_search('-p', $command, true) + 1]);
        $this->assertSame('lscr.io/linuxserver/transmission:latest', end($command));
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
        $this->assertStringContainsString('"Authorization" => ""', $transmission);
        $this->assertStringContainsString('"host" => "127.0.0.1"', $transmission);
        $this->assertStringContainsString('^/user-customer/transmission/', $transmission);
        $this->assertStringContainsString('"/user-customer/transmission/"  => "/transmission/"', $transmission);
        $this->assertStringContainsString('"port" => 9091', $transmission);
    }
}
