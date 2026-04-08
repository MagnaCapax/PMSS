<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class AddUserPostProvisionTest extends TestCase
{
    public function testTrafficSeedingDelegatesToSharedStorageHelper(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/user/add/postProvision.php');

        $this->assertStringContainsString("pmssTrafficSeedInitialState(\$user['name'], dirname(\$homePath), null, 'logProvisionMessage')", $source);
        $this->assertStringContainsString("logProvisionMessage('Seeded traffic files with zero values')", $source);
        $this->pmssAssertStringNotContainsString('pmssTrafficWriteFile(', $source);
        $this->pmssAssertStringNotContainsString('pmssEnsureSafeDir($runtimeStatsDir, 0755)', $source);
    }
}
