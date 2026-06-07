<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class NetworkInfoCharacterizationTest extends TestCase
{
    public function testGlobalsStayAlignedWithHelperResults(): void
    {
        $result = $this->pmssRunRepoInlinePhpRequireJson('scripts/lib/networkInfo.php', 'echo json_encode(['
            .'"link" => $link,'
            .'"linkSpeed" => $linkSpeed,'
            .'"detect" => detectPrimaryInterface(),'
            .'"speed" => getLinkSpeed(detectPrimaryInterface()),'
            .']);', [], '2>/dev/null', true);

        $this->assertSame($result['detect'], $result['link']);
        $this->assertSame($result['speed'], $result['linkSpeed']);
    }

    public function testConfigProbeDelegatesToSharedHelper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/lib/networkInfo.php',
            ["require_once __DIR__.'/network/config.php';", 'networkLoadConfig()'],
            ['/etc/seedbox/config/network']
        );
    }
}
