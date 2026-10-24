<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/configRender.php';

final class TorrentClientsP3ProxyTest extends TestCase
{
    private function render(bool $selected): string
    {
        $template = (string) file_get_contents(dirname(__DIR__, 4).'/etc/seedbox/config/template.lighttpd');
        return \pmssLighttpdRenderUserConfig($template, 't', 30000, 0, 0,
            ['maxProcs' => 2, 'children' => 2], [], $selected);
    }

    public function testUnselectedRouteRendersEmpty(): void
    {
        $config = $this->render(false);
        $this->assertFalse(strpos($config, 'transmission.sock') !== false);
        $this->assertFalse(strpos($config, '##PMSS_TRANSMISSION_PROXY##') !== false);
    }

    public function testSelectedRoutePrecedesCustomIncludes(): void
    {
        $config = $this->render(true);
        $route = strpos($config, '$HTTP["url"] =~ "^/user-t/transmission/"');
        $custom = strpos($config, 'include "/home/t/.lighttpd/custom.d/*.conf"');
        $this->assertTrue(is_int($route) && is_int($custom) && $route < $custom);
        $this->assertStringContainsString('"socket" => "/home/t/.lighttpd/transmission.sock"', $config);
        $this->assertStringContainsString('"/user-t/" =>', $config);
    }

    public function testSelectionNeedsPublishedCapabilityWithoutHostSetup(): void
    {
        $root = $this->pmssMakeTempDir('pmss-p3-proxy-');
        $home = $root.'/t';
        $store = $root.'/store';
        mkdir($home.'/bin/transmission', 0755, true);
        mkdir($store.'/transmission/4.1.3', 0755, true);
        file_put_contents($store.'/transmission/4.1.3/transmission-daemon', 'fixture');
        symlink($store.'/transmission/4.1.3', $home.'/bin/transmission/default');
        $projection = $root.'/projection.json';
        $version = ['client' => 'transmission', 'version' => '4.1.3', 'kind' => 'static'];
        file_put_contents($projection, json_encode(['versions' => [$version]]));
        $this->assertFalse(\pmssLighttpdTransmissionSelected('t', $home, $projection, $store));
        $version['rpcUnixSocket'] = true;
        file_put_contents($projection, json_encode(['versions' => [$version]]));
        $this->assertTrue(\pmssLighttpdTransmissionSelected('t', $home, $projection, $store));
        unlink($store.'/transmission/4.1.3/transmission-daemon');
        $this->assertFalse(\pmssLighttpdTransmissionSelected('t', $home, $projection, $store));
    }

    public function testRenderedRouteParsesWithLighttpdWhenAvailable(): void
    {
        $binary = trim((string) shell_exec('command -v lighttpd 2>/dev/null'));
        if ($binary === '') throw new SkipTest('lighttpd is unavailable');
        $root = $this->pmssMakeTempDir('pmss-p3-lighttpd-');
        $rendered = $this->render(true);
        preg_match('/\$HTTP\["url"\] =~ "\^\/user-t\/transmission\/" \{[^}]+\}/', $rendered, $match);
        $this->assertTrue(isset($match[0]));
        $config = 'server.modules = ( "mod_proxy" )' . "\n"
            .'server.document-root = "'.$root.'"' . "\n"
            .'server.port = 30000' . "\n".$match[0]."\n";
        $path = $root.'/lighttpd.conf';
        file_put_contents($path, $config);
        $output = [];
        $rc = 1;
        exec(escapeshellarg($binary).' -t -f '.escapeshellarg($path).' 2>&1', $output, $rc);
        $this->assertSame(0, $rc, implode("\n", $output));
    }
}
