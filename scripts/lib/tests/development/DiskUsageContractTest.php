<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class DiskUsageContractTest extends TestCase
{
    public function testOutputKeepsSerializedSegmentsWithoutHomeLiteral(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/remote/diskUsage.php');
        $this->assertFalse(strpos($source, '/home/') !== false);
        $this->assertTrue(strpos($source, 'echo serialize($segments);') !== false);

        $binDir = $this->pmssMakeTempDir('pmss-disk-usage-bin-', 0700);
        file_put_contents($binDir.'/df', "#!/bin/sh\nprintf '%s\\n' 'Filesystem Blocks Used Available' 'mock 100 25 75'\n");
        chmod($binDir.'/df', 0700);
        $output = $this->pmssRunRepoPhpScript(
            'scripts/util/remote/diskUsage.php',
            [],
            $this->pmssPathPrefixedEnvironment($binDir)
        );
        $this->assertSame(serialize(['100', '25', '75']), $output);
    }
}
