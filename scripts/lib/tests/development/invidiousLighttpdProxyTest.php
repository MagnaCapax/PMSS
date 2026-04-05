<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class InvidiousLighttpdProxyTest extends TestCase
{
    public function testFragmentMatchesCharacterization(): void
    {
        $fragment = \pmssInvidiousLighttpdProxyFragment('alice', 4100);

        $this->assertSame('a9f464c111549da0b12031161e050ca6ed1a554f4488133c78c3e296240911f9', hash('sha256', $fragment));
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
