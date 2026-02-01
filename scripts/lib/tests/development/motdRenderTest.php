<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/motd/Generator.php';

class MotdRenderTest extends TestCase
{
    public function testRenderMotdTemplateReplacesPlaceholders(): void
    {
        $tpl = "Host=%HOSTNAME% IP=%SERVER_IP% Version=%PMSS_VERSION% APT=%APT_LAST_UPDATE%\n";
        $model = [
            'host'          => 'example-host',
            'ip'            => '192.0.2.10',
            'cpu'           => 'CPU',
            'ram'           => 'RAM',
            'storage'       => 'STOR',
            'pmssVersion'   => 'git/main:2026-02-01@00:00',
            'updateDate'    => '2026-02-01 00:00',
            'aptLastUpdate' => '2026-02-01',
            'uptime'        => 'up 1 day',
            'kernel'        => '6.1.0',
            'netSpeed'      => '1000Mb/s',
            'wgStatus'      => 'active',
            'ovpnStatus'    => 'inactive',
            'distro'        => 'Debian 12 (bookworm)',
            'storageWarn'   => '',
        ];

        $out = \Motd::renderMotdTemplate($tpl, $model, false);
        $this->assertTrue(strpos($out, '%HOSTNAME%') === false, 'HOSTNAME placeholder should be replaced');
        $this->assertTrue(strpos($out, '%SERVER_IP%') === false, 'SERVER_IP placeholder should be replaced');
        $this->assertTrue(strpos($out, '%PMSS_VERSION%') === false, 'PMSS_VERSION placeholder should be replaced');
        $this->assertTrue(strpos($out, '%APT_LAST_UPDATE%') === false, 'APT_LAST_UPDATE placeholder should be replaced');
        $this->assertStringContainsString('example-host', $out);
        $this->assertStringContainsString('192.0.2.10', $out);
        $this->assertStringContainsString('git/main:2026-02-01@00:00', $out);
        $this->assertStringContainsString('2026-02-01', $out);
    }

    public function testRenderMotdTemplateStripsLegacyRuntimeVersionLines(): void
    {
        $tpl = "Hello\nRuntime Version: %RUN_VERSION%\nWorld\n";
        $model = [
            'host'          => 'h',
            'ip'            => 'i',
            'cpu'           => 'c',
            'ram'           => 'r',
            'storage'       => 's',
            'pmssVersion'   => 'v',
            'updateDate'    => 'd',
            'aptLastUpdate' => 'a',
            'uptime'        => 'u',
            'kernel'        => 'k',
            'netSpeed'      => 'n',
            'wgStatus'      => 'w',
            'ovpnStatus'    => 'o',
            'distro'        => 'di',
            'storageWarn'   => '',
        ];

        $out = \Motd::renderMotdTemplate($tpl, $model, false);
        $this->assertTrue(strpos($out, 'Runtime Version:') === false, 'Legacy runtime version line should be removed');
        $this->assertStringContainsString("Hello\n", $out);
        $this->assertStringContainsString("\nWorld\n", $out);
    }

    public function testRenderMotdTemplateAppliesColorToggleForBasics(): void
    {
        $tpl = "Host=%HOSTNAME% Kernel=%KERNEL_VERSION% Net=%NETWORK_SPEED%\n";
        $model = [
            'host'          => 'host',
            'ip'            => 'ip',
            'cpu'           => 'cpu',
            'ram'           => 'ram',
            'storage'       => 'storage',
            'pmssVersion'   => 'v',
            'updateDate'    => 'd',
            'aptLastUpdate' => 'a',
            'uptime'        => 'u',
            'kernel'        => 'kernel',
            'netSpeed'      => '1000Mb/s',
            'wgStatus'      => 'wg',
            'ovpnStatus'    => 'ovpn',
            'distro'        => 'Debian 12',
            'storageWarn'   => '',
        ];

        $plain = \Motd::renderMotdTemplate($tpl, $model, false);
        $this->assertTrue(strpos($plain, "\e[") === false, 'Plain render should not include ANSI escapes');

        $colored = \Motd::renderMotdTemplate($tpl, $model, true);
        $this->assertTrue(strpos($colored, "\e[") !== false, 'Colored render should include ANSI escapes');
    }
}

