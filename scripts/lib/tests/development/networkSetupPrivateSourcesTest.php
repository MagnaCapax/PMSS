<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/network/setup.php';
require_once dirname(__DIR__, 2).'/network/config.php';

/** The dropPrivateSources network setting turns off only the private/shared-range source drops (NAT and home-LAN installs). */
class NetworkSetupPrivateSourcesTest extends TestCase
{
    private const PRIVATE_DROPS = [
        '-A INPUT -i ##IFACE## -s 100.64.0.0/10 -j DROP',
        '-A INPUT -i ##IFACE## -s 172.16.0.0/12 -j DROP',
        '-A INPUT -i ##IFACE## -s 192.168.0.0/16 -j DROP',
    ];

    private const OTHER_BOGON_DROPS = [
        '-A INPUT -i ##IFACE## -s 0.0.0.0/8 -j DROP',
        '-A INPUT -i ##IFACE## -s 127.0.0.0/8 -j DROP',
        '-A INPUT -i ##IFACE## -s 169.254.0.0/16 -j DROP',
        '-A INPUT -i ##IFACE## -s 192.0.0.0/24 -j DROP',
        '-A INPUT -i ##IFACE## -s 192.0.2.0/24 -j DROP',
        '-A INPUT -i ##IFACE## -s 198.18.0.0/15 -j DROP',
        '-A INPUT -i ##IFACE## -s 198.51.100.0/24 -j DROP',
        '-A INPUT -i ##IFACE## -s 203.0.113.0/24 -j DROP',
        '-A INPUT -i ##IFACE## -s 224.0.0.0/3 -j DROP',
    ];

    public function testDefaultAndTrueKeepEveryPrivateRangeDropInTheSameRules(): void
    {
        $default = \networkSetupFirewallCommands(['-A OUTPUT -j ACCEPT']);

        $this->assertSame($default, \networkSetupFirewallCommands(['-A OUTPUT -j ACCEPT'], true));
        foreach (array_merge(self::PRIVATE_DROPS, self::OTHER_BOGON_DROPS) as $rule) {
            $this->assertTrue(in_array($rule, $default['filter'], true), $rule);
        }
    }

    public function testFalseOmitsExactlyTheThreePrivateRangeDropsAndNothingElse(): void
    {
        $default = \networkSetupFirewallCommands(['-A OUTPUT -j ACCEPT']);
        $off = \networkSetupFirewallCommands(['-A OUTPUT -j ACCEPT'], false);

        foreach (self::PRIVATE_DROPS as $rule) {
            $this->assertFalse(in_array($rule, $off['filter'], true), $rule);
        }
        foreach (self::OTHER_BOGON_DROPS as $rule) {
            $this->assertTrue(in_array($rule, $off['filter'], true), $rule);
        }
        // Everything else, order included, is the default policy; NAT is untouched.
        $this->assertSame(array_values(array_diff($default['filter'], self::PRIVATE_DROPS)), $off['filter']);
        $this->assertSame($default['nat'], $off['nat']);
        $this->assertSame(count($default['filter']) - 3, count($off['filter']));
    }

    public function testOnlyABooleanFalseTurnsTheDropsOff(): void
    {
        $this->assertTrue(\networkSetupDropsPrivateSources([]));
        $this->assertTrue(\networkSetupDropsPrivateSources(['interface' => 'eth0', 'speed' => '1000']));
        $this->assertTrue(\networkSetupDropsPrivateSources(['dropPrivateSources' => true]));
        $this->assertFalse(\networkSetupDropsPrivateSources(['dropPrivateSources' => false]));
        // A value that is not the boolean false keeps today's protection.
        foreach ([0, '0', 'false', '', null, [], 'no'] as $value) {
            $this->assertTrue(\networkSetupDropsPrivateSources(['dropPrivateSources' => $value]), var_export($value, true));
        }
    }

    public function testSeededTemplateDocumentsTheSettingAndKeepsTheDefault(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.network');
        $dir = $this->pmssMakeTempDir('pmss-network-template-');
        $this->pmssWriteRelativeFile($dir, 'network', str_replace('##INTERFACE##', 'eth0', $template));
        $config = include $dir.'/network';

        $this->assertSame(true, $config['dropPrivateSources']);
        $this->assertSame('eth0', $config['interface']);
        $this->assertStringContainsString('dropPrivateSources', $template);
        $this->assertTrue(\networkSetupDropsPrivateSources($config));
    }

    public function testSetupNetworkPassesTheLoadedConfigSetting(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/util/setupNetwork.php',
            'networkSetupFirewallCommands($monitoringCommands, networkSetupDropsPrivateSources($networkConfig))'
        );
    }
    public function testTenSlashEightIsNeverInTheDropListWhateverTheSetting(): void
    {
        foreach ([true, false] as $setting) {
            $rules = implode("\n", \networkSetupFirewallCommands([], $setting)['filter']);
            $this->assertStringNotContainsString('10.0.0.0/8', $rules);
        }
    }

    public function testExistingHostConfigWithoutTheKeyKeepsEveryDropAfterUpdate(): void
    {
        // An existing /etc/seedbox/config/network from before this setting: the updater never rewrites it.
        $dir = $this->pmssMakeTempDir('pmss-network-existing-');
        $this->pmssWriteRelativeFile($dir, 'network', "<?php\nreturn array('interface' => 'eno1', 'speed' => '10000');\n");
        $config = array();
        $this->pmssWithEnv(
            array('PMSS_NETWORK_CONFIG' => $dir.'/network'),
            static function () use (&$config): void { $config = \networkLoadConfig(); }
        );
        $this->assertSame('eno1', $config['interface']);
        $this->assertTrue(\networkSetupDropsPrivateSources($config));
        $this->assertSame(
            \networkSetupFirewallCommands(['-A OUTPUT -j ACCEPT']),
            \networkSetupFirewallCommands(['-A OUTPUT -j ACCEPT'], \networkSetupDropsPrivateSources($config))
        );
    }

    public function testConfigWithFalseLoadedFromDiskOmitsTheThreeDrops(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-network-false-');
        $this->pmssWriteRelativeFile($dir, 'network', "<?php\nreturn array('interface' => 'eth0', 'dropPrivateSources' => false);\n");
        $config = array();
        $this->pmssWithEnv(
            array('PMSS_NETWORK_CONFIG' => $dir.'/network'),
            static function () use (&$config): void { $config = \networkLoadConfig(); }
        );
        $rules = \networkSetupFirewallCommands([], \networkSetupDropsPrivateSources($config))['filter'];
        foreach (self::PRIVATE_DROPS as $rule) {
            $this->assertFalse(in_array($rule, $rules, true), $rule);
        }
    }

    public function testRerunningTheSameConfigRendersTheSameRules(): void
    {
        foreach ([true, false] as $setting) {
            $first = \networkSetupFirewallCommands(['-A OUTPUT -j ACCEPT'], $setting);
            $this->assertSame($first, \networkSetupFirewallCommands(['-A OUTPUT -j ACCEPT'], $setting));
        }
    }
}
