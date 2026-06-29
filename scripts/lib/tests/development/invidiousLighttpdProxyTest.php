<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class InvidiousLighttpdProxyTest extends TestCase
{
    public function testFragmentMatchesCharacterization(): void
    {
        $fragment = \pmssLighttpdManagedProxyFragment('invidious', 'alice', 4100);

        $this->assertStringContainsString('"upgrade" => "enable"', $fragment);
        $this->assertSame('7c404b5aec18739b2757634de7acb0ec597330acebfde002e76d4f9717e12f0b', hash('sha256', $fragment));
    }

    public function testUserConfigApplyWiresOptionalPortFileAndManagedFragment(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/lighttpd/userConfigApply.php', [
            ".invidiousPort",
            "pmssLighttpdWriteManagedProxyFragment('invidious'",
            "pmss-invidious.conf",
            "@unlink(\$invidiousConfPath);",
        ]);
    }
}
