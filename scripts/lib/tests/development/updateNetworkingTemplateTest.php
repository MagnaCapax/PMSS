<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/networking.php';

class UpdateNetworkingTemplateTest extends TestCase
{
    public function testEnsureNetworkTemplateWritesTemplateSnapshot(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-network-config-');
        $targetDir = $this->pmssMakeTempDir('pmss-network-target-');
        $target = $targetDir.'/network';
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.network');
        $this->pmssWriteRelativeFile($configDir, 'template.network', $template);
        $messages = array();

        $this->pmssWithEnv(
            array('PMSS_CONFIG_DIR' => $configDir, 'PMSS_NETWORK_CONFIG' => $target),
            function () use (&$messages): void {
                \pmssEnsureNetworkTemplate($this->pmssMakeArrayLogger($messages), static function (): string {
                    return "default via 192.0.2.1 dev ens3 proto dhcp\n";
                });
            }
        );

        $this->assertSame(str_replace('##INTERFACE##', 'ens3', $template), (string) file_get_contents($target));
        $config = include $target;
        $this->assertTrue(is_array($config), 'Expected generated network config to return an array');
        $this->assertSame('ens3', $config['interface']);
        $this->assertSame('1000', $config['speed']);
        $this->assertSame(false, $config['throttle']['progressiveThrottleEnabled']);
        $this->assertSame(array('overagePercent' => 0, 'capMbit' => 100), $config['throttle']['overageStages'][0]);
        $this->assertSame(80, $config['throttle']['limitSoft']);
        $this->assertSame(array('Created default network configuration'), $messages);
    }

    public function testEnsureNetworkTemplateLeavesExistingConfigUntouched(): void
    {
        $configDir = $this->pmssMakeTempDir('pmss-network-config-');
        $targetDir = $this->pmssMakeTempDir('pmss-network-target-');
        $target = $targetDir.'/network';
        $existing = "<?php\nreturn array('interface' => 'eno1');\n";
        $this->pmssWriteRelativeFile($configDir, 'template.network', $this->pmssReadRepoFile('etc/seedbox/config/template.network'));
        file_put_contents($target, $existing);
        $messages = array();

        $this->pmssWithEnv(
            array('PMSS_CONFIG_DIR' => $configDir, 'PMSS_NETWORK_CONFIG' => $target),
            function () use (&$messages): void {
                \pmssEnsureNetworkTemplate($this->pmssMakeArrayLogger($messages), static function (): string {
                    throw new \RuntimeException('Existing config must not trigger route detection');
                });
            }
        );

        $this->assertSame($existing, (string) file_get_contents($target));
        $this->assertSame(array(), $messages);
    }

    public function testDefaultRouteInterfaceRejectsUnsafeAndTunnelDevices(): void
    {
        $this->assertSame('enp1s0', \pmssNetworkDefaultRouteInterface(
            "default dev wg0\ndefault dev eth0;bad\ndefault via 192.0.2.1 dev enp1s0\n"
        ));
        $this->assertSame('eth0', \pmssNetworkDefaultRouteInterface("default dev tun0\ndefault dev tap1\n"));
        $this->assertSame('eth0', \pmssNetworkDefaultRouteInterface("192.0.2.0/24 dev ens3\n"));
        $this->assertSame('bond0.100', \pmssNetworkDefaultRouteInterface("default dev bond0.100\n"));
    }
}
