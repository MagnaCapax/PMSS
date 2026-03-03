<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserResourcesListModesTest extends TestCase
{
    private function loadSource(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../util/userResourcesList.php');
    }

    public function testBriefAndFullModesAreWired(): void
    {
        $src = $this->loadSource();
        $this->assertStringContainsString("pmssCliOption(", $src);
        $this->assertStringContainsString("'brief'", $src);
        $this->assertStringContainsString("'full'", $src);
        $this->assertStringContainsString('choose either --brief or --full', $src);
    }

    public function testBriefColumnContractIsPresent(): void
    {
        $src = $this->loadSource();
        $this->assertStringContainsString('"User", "UID", "MemHigh", "MemMax", "CPUWt", "CPUQt", "BlkWt", "RdBW", "WrBW", "RdIOPS", "WrIOPS"', $src);
    }

    public function testFullColumnContractIsPresent(): void
    {
        $src = $this->loadSource();
        $this->assertStringContainsString('"DskQ", "DskB", "InoQ", "InoB", "NetLim", "NetUsed", "ProcMax", "Suspended"', $src);
    }

    public function testExtendedJsonFieldsArePresent(): void
    {
        $src = $this->loadSource();
        $this->assertStringContainsString("'disk_quota_gib'", $src);
        $this->assertStringContainsString("'network_used_gib'", $src);
        $this->assertStringContainsString("'process_max'", $src);
        $this->assertStringContainsString("'suspended'", $src);
    }
}
