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
        $tpl = "Host=%HOSTNAME% IP=%SERVER_IP% Version=%PMSS_VERSION% APT=%APT_LAST_UPDATE%\n";
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
        $this->pmssAssertStringNotContainsString('%HOSTNAME%', $out, 'HOSTNAME placeholder should be replaced');
        $this->pmssAssertStringNotContainsString('%SERVER_IP%', $out, 'SERVER_IP placeholder should be replaced');
        $this->pmssAssertStringNotContainsString('%PMSS_VERSION%', $out, 'PMSS_VERSION placeholder should be replaced');
        $this->pmssAssertStringNotContainsString('%APT_LAST_UPDATE%', $out, 'APT_LAST_UPDATE placeholder should be replaced');
        $this->assertStringContainsString('example-host', $out);
        $this->assertStringContainsString('192.0.2.10', $out);
        $this->assertStringContainsString('git/main:2026-02-01@00:00', $out);
        $this->assertStringContainsString('2026-02-01', $out);
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
        $this->assertStringContainsString("\e[", $colored, 'Colored render should include ANSI escapes');
    }
}
