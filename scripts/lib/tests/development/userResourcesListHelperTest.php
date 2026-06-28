<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/resourcesList.php';

class UserResourcesListHelperTest extends TestCase
{
    public function testQuotaStateDerivesBurstAndInodes(): void
    {
        $state = \pmssUserResourcesListQuotaState(['quota' => 20], false);

        $this->assertEquals(20, $state['disk_quota_gib']);
        $this->assertEquals(25, $state['disk_burst_gib']);
        $this->assertEquals(15000, $state['inode_quota']);
        $this->assertEquals(18750, $state['inode_burst']);
        $this->assertTrue($state['suspended'] === false);
    }

    public function testQuotaStateHonoursExplicitBurstAndSuspendedFlag(): void
    {
        $state = \pmssUserResourcesListQuotaState(['quota' => 100, 'quotaBurst' => 180, 'suspended' => true], false);

        $this->assertEquals(180, $state['disk_burst_gib']);
        $this->assertEquals(50000, $state['inode_quota']);
        $this->assertEquals(62500, $state['inode_burst']);
        $this->assertTrue($state['suspended']);
    }

    public function testBinaryFormatterKeepsCompactUnits(): void
    {
        $this->assertEquals('-', \pmssUserResourcesListBinaryFormat(null));
        $this->assertEquals('1K', \pmssUserResourcesListBinaryFormat(1024));
        $this->assertEquals('1.5K', \pmssUserResourcesListBinaryFormat(1536));
    }

    public function testRowBuildFullModeUsesStableTextContracts(): void
    {
        $row = \pmssUserResourcesListRowBuild([
            'user' => 'alice',
            'uid' => 1001,
            'memory_high' => 1073741824,
            'memory_max' => 2147483648,
            'cpu_weight' => 200,
            'cpu_quota_percent' => 150,
            'io_weight' => 300,
            'io_read_bandwidth' => 1048576,
            'io_write_bandwidth' => 2097152,
            'io_read_iops' => 1000,
            'io_write_iops' => 2000,
            'disk_quota_gib' => 50,
            'disk_burst_gib' => 75,
            'inode_quota' => 25000,
            'inode_burst' => 31250,
            'network_limit_gib' => null,
            'network_used_gib' => 12.5,
            'process_max' => null,
            'suspended' => true,
        ], 'full');

        foreach ([0 => 'alice', 2 => '1G', 3 => '2G', 7 => '1M', 11 => '50G', 15 => 'inf', 16 => '12.5G', 17 => 'inf', 18 => 'yes'] as $index => $expected) {
            $this->assertEquals($expected, $row[$index], 'column '.$index);
        }
    }

    public function testRowBuildBriefModeSnapshot(): void
    {
        $row = \pmssUserResourcesListRowBuild([
            'user' => 'longusername',
            'uid' => 1002,
            'memory_high' => 536870912,
            'memory_max' => null,
            'cpu_weight' => null,
            'cpu_quota_percent' => 75,
            'io_weight' => 250,
            'io_read_bandwidth' => null,
            'io_write_bandwidth' => 1048576,
            'io_read_iops' => null,
            'io_write_iops' => 300,
        ], 'brief');

        $this->assertSame(
            ['longuserna', '1002', '512M', '-', '-', '75%', '250', '-', '1M', '-', '300'],
            $row
        );
    }
}
