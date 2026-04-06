<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class InvidiousLighttpdProxyTest extends TestCase
{
    public function testFragmentMatchesCharacterization(): void
    {
        $fragment = \pmssInvidiousLighttpdProxyFragment('alice', 4100);

        $this->assertSame('a6b6484bc3cc4767978a2a1eeb23869d17438625d3c2c93ccc9151fac24cb6dd', hash('sha256', $fragment));
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
