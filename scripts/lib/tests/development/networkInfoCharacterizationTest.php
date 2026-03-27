<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class NetworkInfoCharacterizationTest extends TestCase
{
    public function testGlobalsStayAlignedWithHelperResults(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $result = $this->pmssRunInlinePhpJson(
            'require_once '.var_export($repoRoot.'/scripts/lib/networkInfo.php', true).';'
            .'echo json_encode(['
            .'"link" => $link,'
            .'"linkSpeed" => $linkSpeed,'
            .'"detect" => detectPrimaryInterface(),'
            .'"speed" => getLinkSpeed(detectPrimaryInterface()),'
            .']);'
        );

        $this->assertSame($result['detect'], $result['link']);
        $this->assertSame($result['speed'], $result['linkSpeed']);
    }

    public function testConfigProbeDelegatesToSharedHelper(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/networkInfo.php');

        $this->assertStringContainsString("require_once __DIR__.'/network/config.php';", $src);
        $this->assertStringContainsString('networkLoadConfig()', $src);
        $this->assertSame(0, substr_count($src, '/etc/seedbox/config/network'));
    }
}
