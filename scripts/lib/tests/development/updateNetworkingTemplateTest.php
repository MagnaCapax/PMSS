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
                \pmssEnsureNetworkTemplate($this->pmssMakeArrayLogger($messages));
            }
        );

        $this->assertSame($template, (string) file_get_contents($target));
        $config = include $target;
        $this->assertTrue(is_array($config), 'Expected generated network config to return an array');
        $this->assertSame('eth0', $config['interface']);
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
                \pmssEnsureNetworkTemplate($this->pmssMakeArrayLogger($messages));
            }
        );

        $this->assertSame($existing, (string) file_get_contents($target));
        $this->assertSame(array(), $messages);
    }
}
