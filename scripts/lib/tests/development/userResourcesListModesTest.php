<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserResourcesListModesTest extends TestCase
{
    public function testResourcesListModeContractsArePresent(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/resourcesList.php');
        $this->assertStringContainsAllStrings([
            "pmssCliOption(",
            "'brief'",
            "'full'",
            'choose either --brief or --full',
            '"User", "UID", "MemHigh", "MemMax", "CPUWt", "CPUQt", "BlkWt", "RdBW", "WrBW", "RdIOPS", "WrIOPS"',
            '"DskQ", "DskB", "InoQ", "InoB", "NetLim", "NetUsed", "ProcMax", "Suspended"',
            "'disk_quota_gib'",
            "'network_used_gib'",
            "'process_max'",
            "'suspended'",
        ], $src);
    }
}
