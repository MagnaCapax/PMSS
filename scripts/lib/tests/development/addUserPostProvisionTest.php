<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class AddUserPostProvisionTest extends TestCase
{
    public function testTrafficSeedingUsesSharedManagedWriter(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/postProvision.php');

        $this->assertStringContainsString('pmssEnsureSafeDir($runtimeStatsDir, 0755)', $source);
        $this->assertStringContainsString('pmssTrafficWriteFile($trafficPath, $serializedTraffic, $user[\'name\'], 0640, true)', $source);
        $this->assertStringContainsString('pmssTrafficWriteFile($runtimeStatsPath, $serializedTraffic, \'root\', 0600, false)', $source);
        $this->assertStringContainsString("['raw'=>\$zeroRaw,'daily'=>[]]", $source);
        $this->pmssAssertStringNotContainsString('@file_put_contents($trafficPath', $source);
        $this->pmssAssertStringNotContainsString('@file_put_contents($runtimeStatsPath', $source);
        $this->pmssAssertStringNotContainsString("'display'=>", $source);
    }

    public function testTrafficSeedingLogsPreparationFailuresWithoutAbortingAccountCreation(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/postProvision.php');

        $this->assertStringContainsString("logProvisionMessage('Failed to prepare runtime traffic directory')", $source);
        $this->assertStringContainsString("logProvisionMessage('Failed to seed traffic file: '.\$pathKey)", $source);
        $this->assertStringContainsString("logProvisionMessage('Failed to seed runtime traffic cache')", $source);
        $this->assertStringContainsString("!\$seedFailed && logProvisionMessage('Seeded traffic files with zero values');", $source);
    }
}
