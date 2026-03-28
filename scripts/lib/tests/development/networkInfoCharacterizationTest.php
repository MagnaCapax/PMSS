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
        $path = 'scripts/lib/networkInfo.php';
        $this->pmssAssertRepoFileContainsAllStrings($path, ["require_once __DIR__.'/network/config.php';", 'networkLoadConfig()']);
        $this->pmssAssertRepoFileSubstringCount($path, '/etc/seedbox/config/network', 0);
    }
}
