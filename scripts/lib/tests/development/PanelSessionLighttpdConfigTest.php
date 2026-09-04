<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class PanelSessionLighttpdConfigTest extends TestCase
{
    private function resources(): array
    {
        return ['maxProcs' => 2, 'children' => 6];
    }

    private function legacyRender(string $template, string $user = 'alice'): string
    {
        $config = str_replace(
            ["##username", "##serverPort", "##rclonePort", "##qbittorrentPort", "##PMSS_WEBDAV_WWW_POLICY##"],
            [$user, 31234, 32345, 33456, \pmssWebdavWwwPolicyBlock($user)],
            $template
        );
        $config = preg_replace(
            ['/("max-procs"\s*=>\s*)[0-9]+/', '/("PHP_FCGI_CHILDREN"\s*=>\s*")[0-9]+(")/'],
            ['${1}2', '${1}6${2}'],
            $config,
            1
        );

        return \pmssClampLighttpdBandwidthLimits(is_string($config) ? $config : $template);
    }

    private function render(string $template, array $panelSessionLogin = []): string
    {
        return \pmssLighttpdRenderUserConfig(
            $template,
            'alice',
            31234,
            32345,
            33456,
            $this->resources(),
            $panelSessionLogin
        );
    }

    public function testPanelSessionDisabledRenderIsByteIdenticalToLegacyRenderer(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $expected = $this->legacyRender($template);

        foreach ([[], ['enabled' => false, 'moduleLoadable' => true, 'gatePath' => '/tmp/unused.lua', 'gateExists' => true]] as $options) {
            $rendered = $this->render($template, $options);
            $this->assertSame($expected, $rendered, 'panelSessionLogin off must not change generated lighttpd config');
            $this->assertStringContainsAndOmitsStrings(
                ['"/user-alice/"', '"/webdav-alice/"', '"method" => "basic"'],
                ['mod_magnet', 'magnet.attract-raw-url-to', 'auth.extern-authn', 'panelSessionLogin.php'],
                $rendered
            );
        }
    }

    public function testPanelSessionEnabledWithoutModuleKeepsBasicOnlyConfig(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $gate = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-panel-gate-').'/panelSessionGate.lua', '-- gate');
        $rendered = $this->render($template, [
            'enabled' => true,
            'moduleLoadable' => false,
            'gatePath' => $gate,
            'gateExists' => true,
        ]);

        $this->assertStringContainsAndOmitsStrings(
            ['"/user-alice/"', '"/webdav-alice/"', '"method" => "basic"'],
            ['mod_magnet', 'magnet.attract-raw-url-to', 'auth.extern-authn'],
            $rendered
        );
    }

    public function testPanelSessionEnabledWithoutDeployedGateKeepsBasicOnlyConfig(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $missingGate = $this->pmssMakeTempDir('pmss-panel-gate-missing-').'/panelSessionGate.lua';
        $rendered = $this->render($template, [
            'enabled' => true,
            'moduleLoadable' => true,
            'gatePath' => $missingGate,
        ]);

        $this->assertStringContainsAndOmitsStrings(
            ['"/user-alice/"', '"/webdav-alice/"', '"method" => "basic"'],
            ['mod_magnet', 'magnet.attract-raw-url-to', 'auth.extern-authn'],
            $rendered
        );
    }

    public function testPanelSessionEnabledWithModuleAndGateEmitsCookieOrBasicMagnetBlock(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $gate = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-panel-gate-').'/panelSessionGate.lua', '-- gate');
        $rendered = $this->render($template, [
            'enabled' => true,
            'moduleLoadable' => true,
            'gatePath' => $gate,
            'gateExists' => true,
        ]);

        $this->assertOrderedStrings(['"mod_magnet",', '"mod_auth",'], $rendered);
        $this->assertStringContainsAllStrings([
            '$HTTP["url"] =~ "^/user-alice($|/)"',
            'auth.extern-authn = "enable"',
            'magnet.attract-raw-url-to = ( "'.$gate.'" )',
            '"/user-alice/"',
            '"/webdav-alice/"',
            '"method" => "basic"',
        ], $rendered);
        $this->assertSame(1, substr_count($rendered, 'magnet.attract-raw-url-to'));
        $this->assertSame(1, substr_count($rendered, 'mod_magnet'));
        $this->assertStringNotContainsString('$HTTP["url"] =~ "^/webdav-alice', $this->magnetBlock($rendered));
    }

    public function testPanelSessionGateDeploysToUserLighttpdDirectoryBeforeRenderReference(): void
    {
        $root = $this->pmssMakeTempDir('pmss-panel-home-');
        $home = $this->pmssEnsureDir($root.'/alice');
        $this->pmssEnsureDir($home.'/.lighttpd');
        $module = $this->pmssWriteFile($this->pmssMakeTempDir('pmss-panel-module-').'/mod_magnet.so', 'module');

        $this->assertTrue(\pmssLighttpdPanelSessionGateDeploy('alice', $home));
        $gatePath = $home.'/.lighttpd/panelSessionGate.lua';
        $this->assertSame($this->pmssReadRepoFile('scripts/lib/lighttpd/panelSessionGate.lua'), (string) file_get_contents($gatePath));
        $this->assertSame(0600, fileperms($gatePath) & 0777);

        $options = \pmssLighttpdPanelSessionLoginOptions('alice', $home, true, [$module]);
        $this->assertTrue(\pmssLighttpdPanelSessionLoginShouldEmit($options));
        $rendered = \pmssLighttpdRenderUserConfig(
            $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd'),
            'alice',
            31234,
            32345,
            33456,
            $this->resources(),
            $options
        );

        $this->assertStringContainsString('magnet.attract-raw-url-to = ( "'.$gatePath.'" )', $rendered);
    }

    public function testUpdateBootstrapStagesScriptsTreeBeforePhaseTwoCanReferenceLuaSource(): void
    {
        $this->assertTrue(is_file($this->pmssRepoPath('scripts/lib/lighttpd/panelSessionGate.lua')));
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/update.php', [
            '// Stage /scripts with an atomic rename swap.',
            '$scriptsSource = $tmp.\'/scripts\';',
            'pmssAtomicSwapDirectory(\'/scripts\', $scriptsStaging, $scriptsBackup, \'scripts\');',
            'flattenScriptsLayout();',
            "pmssBootstrapPhpCommand('/scripts/util/update-step2.php')",
        ]);
    }

    private function magnetBlock(string $config): string
    {
        $offset = strpos($config, '# PMSS panel session-cookie login');
        return $offset === false ? '' : substr($config, $offset);
    }
}
