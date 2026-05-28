<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserResourcesListModesTest extends TestCase
{
    public function testBriefAndFullModesAreWired(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/resourcesList.php');
        $this->assertStringContainsAllStrings(["pmssCliOption(", "'brief'", "'full'", 'choose either --brief or --full'], $src);
    }

    public function testBriefColumnContractIsPresent(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/resourcesList.php');
        $this->assertStringContainsString('"User", "UID", "MemHigh", "MemMax", "CPUWt", "CPUQt", "BlkWt", "RdBW", "WrBW", "RdIOPS", "WrIOPS"', $src);
    }

    public function testFullColumnContractIsPresent(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/resourcesList.php');
        $this->assertStringContainsString('"DskQ", "DskB", "InoQ", "InoB", "NetLim", "NetUsed", "ProcMax", "Suspended"', $src);
    }

    public function testExtendedJsonFieldsArePresent(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/user/resourcesList.php');
        $this->assertStringContainsAllStrings(["'disk_quota_gib'", "'network_used_gib'", "'process_max'", "'suspended'"], $src);
    }
}
