<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/motd/Generator.php';

class MotdRenderTest extends TestCase
{
    private function motdModel(array $overrides = []): array
    {
        return array_merge([
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
        ], $overrides);
    }

    public function testRenderMotdTemplateReplacesPlaceholders(): void
    {
        $tpl = 'Host=%HOSTNAME% IP=%SERVER_IP% CPU=%SERVER_CPU% RAM=%SERVER_RAM% Storage=%SERVER_STORAGE% '
            ."Version=%PMSS_VERSION% Updated=%UPDATE_DATE% APT=%APT_LAST_UPDATE% Up=%UPTIME% "
            ."Kernel=%KERNEL_VERSION% Net=%NETWORK_SPEED% WG=%WIREGUARD_STATUS% OVPN=%OPENVPN_STATUS% Distro=%DISTRO%\n";
        $model = $this->motdModel([
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
        ]);

        $out = \Motd::renderMotdTemplate($tpl, $model, false);
        $this->assertSame(
            'Host=example-host IP=192.0.2.10 CPU=CPU RAM=RAM Storage=STOR '
            ."Version=git/main:2026-02-01@00:00 Updated=2026-02-01 00:00 APT=2026-02-01 Up=up 1 day "
            ."Kernel=6.1.0 Net=1000Mb/s WG=active OVPN=inactive Distro=Debian 12 (bookworm)\n",
            $out
        );
    }

    public function testRenderMotdTemplateStripsLegacyRuntimeVersionLines(): void
    {
        $tpl = "Hello\nRuntime Version: %RUN_VERSION%\nWorld\n";
        $model = $this->motdModel();

        $out = \Motd::renderMotdTemplate($tpl, $model, false);
        $this->pmssAssertStringNotContainsString('Runtime Version:', $out, 'Legacy runtime version line should be removed');
        $this->assertStringContainsString("Hello\n", $out);
        $this->assertStringContainsString("\nWorld\n", $out);
    }

    public function testRenderMotdTemplateAppliesColorToggleForBasics(): void
    {
        $tpl = "Host=%HOSTNAME% Kernel=%KERNEL_VERSION% Net=%NETWORK_SPEED%\n";
        $model = $this->motdModel();

        $plain = \Motd::renderMotdTemplate($tpl, $model, false);
        $this->pmssAssertStringNotContainsString("\e[", $plain, 'Plain render should not include ANSI escapes');

        $colored = \Motd::renderMotdTemplate($tpl, $model, true);
        $this->assertSame(
            "Host=\e[1;36mhost\e[0m Kernel=\e[34mkernel\e[0m Net=\e[32m1000Mb/s\e[0m\n",
            $colored
        );
    }
}
