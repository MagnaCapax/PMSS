<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class InvidiousLighttpdProxyTest extends TestCase
{
    public function testFragmentPublishesPublicTenantPath(): void
    {
        $fragment = \pmssInvidiousLighttpdProxyFragment('alice', 4100);

        $this->assertStringContainsString('^/public-alice/invidious($|/)', $fragment);
        $this->assertStringContainsString('"/public-alice/invidious/"  => "/"', $fragment);
        $this->assertStringContainsString('"/public-alice/invidious" => ""', $fragment);
    }

    public function testFragmentPublishesPrivateAppsTenantPath(): void
    {
        $fragment = \pmssInvidiousLighttpdProxyFragment('alice', 4100);

        $this->assertStringContainsString('^/user-alice/apps/invidious($|/)', $fragment);
        $this->assertStringContainsString('"/user-alice/apps/invidious/"  => "/"', $fragment);
        $this->assertStringContainsString('"/user-alice/apps/invidious" => ""', $fragment);
    }

    public function testFragmentTargetsLoopbackOnly(): void
    {
        $fragment = \pmssInvidiousLighttpdProxyFragment('alice', 4100);

        $this->assertEquals(2, substr_count($fragment, '"host" => "127.0.0.1"'));
        $this->assertTrue(strpos($fragment, '0.0.0.0') === false);
    }

    public function testFragmentPreservesForwardedHeadersInBothScopes(): void
    {
        $fragment = \pmssInvidiousLighttpdProxyFragment('alice', 4100);

        $this->assertEquals(2, substr_count($fragment, 'proxy.forwarded = ( "for" => 1,'));
        $this->assertEquals(2, substr_count($fragment, '"host" => 1,'));
    }

    public function testUserConfigApplyWiresOptionalPortFileAndManagedFragment(): void
    {
        $path = dirname(__DIR__, 2).'/lighttpd/userConfigApply.php';
        $contents = (string) @file_get_contents($path);

        $this->assertTrue($contents !== '', 'Unable to read '.$path);
        $this->assertStringContainsString(".invidiousPort", $contents);
        $this->assertStringContainsString("pmssInvidiousLighttpdProxyFragment", $contents);
        $this->assertStringContainsString("pmss-invidious.conf", $contents);
        $this->assertStringContainsString("@unlink(\$invidiousConfPath);", $contents);
    }
}
