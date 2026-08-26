<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/postProvision.php';

final class AddUserPostProvisionTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-add-user-bonus-');
    }

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

    public function testBonusQuotaPersistenceIsOptionalAtomicAndSymlinkSafe(): void
    {
        $home = $this->tempDir.'/alice';
        $this->pmssEnsureDir($home);

        $this->assertTrue(\pmssAddUserBonusQuotaPersist(['name' => 'alice'], $home));
        $this->assertFalse(file_exists($home.'/.bonusQuota'));
        $this->assertTrue(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => 0], $home));
        $this->assertFalse(file_exists($home.'/.bonusQuota'));
        $this->assertFalse(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => -1], $home));
        $this->assertFalse(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => '25'], $home));

        $this->assertTrue(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => 25], $home));
        $this->assertSame('25', trim((string) file_get_contents($home.'/.bonusQuota')));
        $this->assertSame(0640, fileperms($home.'/.bonusQuota') & 0777);

        unlink($home.'/.bonusQuota');
        $outside = $this->tempDir.'/outside';
        file_put_contents($outside, 'unchanged');
        symlink($outside, $home.'/.bonusQuota');
        $this->assertFalse(\pmssAddUserBonusQuotaPersist(['name' => 'alice', 'bonusQuotaGiB' => 50], $home));
        $this->assertSame('unchanged', file_get_contents($outside));
    }
}
