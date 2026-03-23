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

        $this->assertEquals('alice', $row[0]);
        $this->assertEquals('1G', $row[2]);
        $this->assertEquals('2G', $row[3]);
        $this->assertEquals('1M', $row[7]);
        $this->assertEquals('50G', $row[11]);
        $this->assertEquals('inf', $row[15]);
        $this->assertEquals('12.5G', $row[16]);
        $this->assertEquals('inf', $row[17]);
        $this->assertEquals('yes', $row[18]);
    }
}
