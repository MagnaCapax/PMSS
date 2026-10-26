<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/resourcesList.php';

class UserResourcesListModesTest extends TestCase
{
    public function testResourcesListModeContractsArePresent(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/resourcesList.php', [
            "pmssCliOption(",
            "'brief'",
            "'full'",
            'choose either --brief or --full',
        ]);
        $brief = ['User', 'UID', 'MemHigh', 'MemMax', 'CPUWt', 'CPUQt', 'BlkWt', 'RdBW', 'WrBW', 'RdIOPS', 'WrIOPS'];
        $this->assertSame($brief, array_column(\pmssUserResourcesListColumns([], 'brief'), 0));
        $this->assertSame(array_merge($brief, ['DskQ', 'DskB', 'InoQ', 'InoB', 'NetLim', 'NetUsed', 'ProcMax', 'Suspended']), array_column(\pmssUserResourcesListColumns([], 'full'), 0));
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/user/resourcesListDisplay.php', [
            "'disk_quota_gib'",
            "'network_used_gib'",
            "'process_max'",
            "'suspended'",
        ]);
    }
}
