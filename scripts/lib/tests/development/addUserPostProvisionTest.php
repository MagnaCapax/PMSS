<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class AddUserPostProvisionTest extends TestCase
{
    public function testTrafficSeedingDelegatesToSharedStorageHelper(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/lib/user/add/postProvision.php',
            [
                "pmssTrafficSeedInitialState(\$user['name'], dirname(\$homePath), null, 'logProvisionMessage')",
                "logProvisionMessage('Seeded traffic files with zero values')",
            ]
        );
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/user/add/postProvision.php', ['pmssTrafficWriteFile(', 'pmssEnsureSafeDir($runtimeStatsDir, 0755)']);
    }
}
