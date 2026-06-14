<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/services/quota.php';

class QuotaFstabRepairTest extends TestCase
{
    private function quotaFstabRepairRun(string $content): array
    {
        ['fstab' => $fstab] = $this->pmssMountFixtureCreate('pmss-quota-repair-', $content);
        $messages = $this->pmssArrayLoggerMessages(function (callable $logger) use ($fstab): void {
            \pmssEnsureQuotaOptions('/home', null, $logger, $fstab);
        });

        return [(string) file_get_contents($fstab), $messages];
    }

    public function testMalformedQuotaValueIsReplacedNotDuplicated(): void
    {
        $control = chr(3);
        [$updated, $messages] = $this->quotaFstabRepairRun(
            "UUID=abc /home ext4 defaults,nofail,noatime,usrjquota=aquota.user,grpjquota=aquota.gro{$control},jqfmt=vfsv1 0 0\n"
        );

        $this->assertStringContainsString('grpjquota=aquota.group', $updated);
        $this->assertFalse(strpos($updated, 'aquota.gro'.$control) !== false, 'expected malformed group quota option removed');
        $this->assertEquals(1, substr_count($updated, 'grpjquota='), 'expected one group quota option');
        $this->assertTrue($this->pmssMessagesContain($messages, 'Updated quota options'), 'expected update log');
    }

    public function testDuplicateQuotaKeysCollapseToCanonicalValues(): void
    {
        [$updated] = $this->quotaFstabRepairRun(
            "UUID=abc /home ext4 defaults,usrjquota=bad.user,usrjquota=aquota.user,grpjquota=bad.group,grpjquota=aquota.group,jqfmt=bad,jqfmt=vfsv1 0 0\n"
        );

        $this->assertEquals(1, substr_count($updated, 'usrjquota='));
        $this->assertEquals(1, substr_count($updated, 'grpjquota='));
        $this->assertEquals(1, substr_count($updated, 'jqfmt='));
        $this->assertStringContainsString('usrjquota=aquota.user', $updated);
        $this->assertStringContainsString('grpjquota=aquota.group', $updated);
        $this->assertStringContainsString('jqfmt=vfsv1', $updated);
    }
}
